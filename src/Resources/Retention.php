<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\Degrade\RetentionDegrade;
use Agreely\Sdk\Duration;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyUnavailableError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\HostInput;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\Types\PurgeDeclaration;
use Agreely\Sdk\Types\PurgeMethod;
use Agreely\Sdk\Types\RetentionRule;
use Agreely\Sdk\Types\RetentionRuleDetail;
use Agreely\Sdk\Types\RetentionRuleList;
use Agreely\Sdk\Types\RetentionRuleSnapshot;
use Agreely\Sdk\Types\RetentionSweep;
use Agreely\Sdk\Types\RulesForPurge;

/**
 * The host-retention resource (scope: 'retention').
 *
 * « Agreely décide et surveille, l'hôte exécute et rend compte. » Agreely DECIDES the
 * rules and RECORDS what the host declares; the host reads the decisions, runs its
 * purges, and DECLARES them. Agreely observes nothing in the host's systems and
 * verifies none of it: every write answers `status: "declared"`, never "verified".
 *
 * Neither declaration is ever auto-retried, and both REQUIRE an Idempotency-Key
 * passed in the $options argument, because it travels as a HEADER. Putting it in the
 * body is refused client-side rather than silently dropped
 * ({@see HostInput::closed()}), and the digest the server stores is BOUND TO THE API
 * KEY that declared: replaying a declaration after rotating the key records a SECOND
 * declaration, so settle every pending declaration with the old key before revoking it.
 */
final class Retention
{
    /** The `recordsAffected` upper bound, mirrored from the server. */
    public const PURGE_RECORDS_MAX = 1_000_000_000;

    /** At most this many `references` per declaration; a larger purge is declared in several. */
    public const PURGE_REFERENCES_MAX = 1000;

    /** A pass's `sweptAt` must fall within this window before now. */
    public const SWEEP_MAX_AGE_MS = 24 * 3_600_000;

    /** The members a purge declaration accepts, and no others. */
    private const PURGE_MEMBERS = [
        'ranAt',
        'recordsAffected',
        'method',
        'anonymizationProcessKey',
        'coveredFrom',
        'coveredUntil',
        'hostSystem',
        'hostCategory',
        'references',
    ];

    /** The members a pass accepts, and no others. */
    private const SWEEP_MEMBERS = ['sweptAt', 'hostSystem'];

    public function __construct(
        private readonly Transport $transport,
        private readonly int $maxDegradeWindowMs = Duration::DEFAULT_MAX_DEGRADE_WINDOW_MS,
    ) {
    }

    /**
     * Every rule the organisation decided (ARCHIVED ones included), or only those
     * changed since `changedSince` (a previous response's `cursor`). `ruleKeys` is
     * always the complete current key set, so a rule deleted in Agreely shows up as a
     * key that disappeared.
     *
     * A purge job usually wants {@see Retention::rulesForPurge()} instead, which does
     * the incremental merge AND decides the outage for you.
     *
     * @param array{changedSince?:string} $input
     */
    public function listRules(array $input = []): RetentionRuleList
    {
        $changedSince = $input['changedSince'] ?? null;
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/retention/rules',
            query: ['changedSince' => is_string($changedSince) ? $changedSince : null],
            idempotentRetry: true,
        ));
        return RetentionRuleList::fromWire($wire);
    }

    /**
     * One rule, plus the instants of the host's last declared purge and pass under it.
     * An unknown key, another company's key and a malformed key are the SAME
     * AgreelyNotFoundError with the same body, so it cannot be used to probe another
     * tenant's rules.
     */
    public function getRule(string $ruleKey): RetentionRuleDetail
    {
        $key = HostInput::pathKey($ruleKey, 'retention.getRule', 'ruleKey');
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/retention/rules/' . rawurlencode($key),
            idempotentRetry: true,
        ));
        return RetentionRuleDetail::fromWire($wire);
    }

    /**
     * THE RULES A PURGE JOB MAY ACT ON THIS RUN, with the outage already decided for it.
     *
     * Agreely answers: the rules are read (incrementally when the previous run's
     * `snapshot` is passed) and returned with a fresh snapshot to persist.
     *
     * Agreely is unreachable (503, network, timeout): by DEFAULT the result ABSTAINS and
     * the job must not purge this run. Missing a SHORTENED rule keeps data a little too
     * long; missing a LENGTHENED rule (a legal hold) destroys what had to be kept, and
     * that does not repair. See {@see RetentionDegrade} for the whole asymmetry.
     *
     * Anything else (401/403, 402, 422, 429) THROWS: it is not an outage, and treating
     * it as one would hide a key or billing problem behind a skipped run.
     *
     * The options are validated BEFORE any wire call:
     *   snapshot       the {@see RetentionRuleSnapshot} the previous run persisted
     *   onOutage       "abstain" (the default) or "use-snapshot"
     *   maxSnapshotAge with "use-snapshot", the oldest snapshot accepted, e.g. "26h",
     *                  itself capped by the client's `maxDegradeWindow` (default 24h)
     *   onDegrade      with "use-snapshot", the MANDATORY evidence sink, called once
     *                  with a {@see \Agreely\Sdk\Degrade\RetentionDegradeContext}
     *
     * @param array{snapshot?:RetentionRuleSnapshot|null,onOutage?:string,maxSnapshotAge?:string,onDegrade?:callable} $options
     */
    public function rulesForPurge(array $options = []): RulesForPurge
    {
        $maxSnapshotAgeMs = RetentionDegrade::window($options, $this->maxDegradeWindowMs);
        $snapshot = $options['snapshot'] ?? null;
        if ($snapshot !== null && !$snapshot instanceof RetentionRuleSnapshot) {
            throw new AgreelyConfigError(
                'rulesForPurge snapshot must be a ' . RetentionRuleSnapshot::class
                . ' (rebuild the one you persisted with RetentionRuleSnapshot::fromArray()), or null.',
            );
        }
        try {
            return RulesForPurge::live($this->sync($snapshot));
        } catch (AgreelyUnavailableError $error) {
            return RetentionDegrade::decide($options, $maxSnapshotAgeMs, $error);
        }
    }

    /**
     * DECLARE a purge a host system ran under a rule. 201 records it; the same
     * Idempotency-Key with the same declaration replays the STORED one
     * (`replayed: true`); the same key with a DIFFERENT declaration is a 422
     * `idempotency_key_reused`. A 409 is AgreelyConflictError: retry with the SAME key.
     * A `method` that differs from the rule's `action` is recorded and flagged in
     * `warnings`, never refused.
     *
     * REFUSED CLIENT-SIDE, before any wire call (AgreelyConfigError):
     *   - a missing or malformed idempotencyKey, or one placed in the body
     *   - a `method` other than "destroyed" / "anonymized" ({@see PurgeMethod})
     *   - an "anonymized" purge with no process key, or a "destroyed" one with one
     *   - `recordsAffected` below 1 (a pass that found nothing is a SWEEP) or above
     *     1,000,000,000
     *   - more than 1000 `references`, or more references than `recordsAffected`
     *   - a malformed host token, instant or calendar day
     *
     * The body is built from the named members only, so nothing else on the input can
     * reach the wire. NEVER auto-retried.
     *
     * @param array{
     *     ranAt: \DateTimeInterface|string,
     *     recordsAffected: int,
     *     method: string,
     *     anonymizationProcessKey?: string,
     *     coveredFrom: string,
     *     coveredUntil: string,
     *     hostSystem: string,
     *     hostCategory: string,
     *     references?: list<string>
     * } $input
     * @param array{idempotencyKey:string} $options
     */
    public function declarePurge(string $ruleKey, array $input, array $options): PurgeDeclaration
    {
        $label = 'retention.declarePurge';
        $key = HostInput::pathKey($ruleKey, $label, 'ruleKey');
        HostInput::closed($input, self::PURGE_MEMBERS, $label);
        $idempotencyKey = HostInput::idempotencyKey($options, $label);

        $method = PurgeMethod::assert($input['method'] ?? null, $label);
        $processKey = $input['anonymizationProcessKey'] ?? null;
        if ($method === PurgeMethod::ANONYMIZED && (!is_string($processKey) || trim($processKey) === '')) {
            throw new AgreelyConfigError(
                "{$label}: an \"anonymized\" purge requires \"anonymizationProcessKey\", the process that was "
                . 'applied. It is never defaulted from the rule.',
            );
        }
        if ($method === PurgeMethod::DESTROYED && $processKey !== null) {
            throw new AgreelyConfigError("{$label}: a \"destroyed\" purge takes no anonymizationProcessKey.");
        }

        $records = $this->records($input['recordsAffected'] ?? null, $label);
        $references = $this->references($input['references'] ?? null, $records, $label);

        $body = [
            'ranAt' => HostInput::instant($input['ranAt'] ?? null, "{$label}: ranAt")['wire'],
            'recordsAffected' => $records,
            'method' => $method,
            'coveredFrom' => HostInput::calendarDay($input['coveredFrom'] ?? null, "{$label}: coveredFrom"),
            'coveredUntil' => HostInput::calendarDay($input['coveredUntil'] ?? null, "{$label}: coveredUntil"),
            'hostSystem' => HostInput::hostToken($input['hostSystem'] ?? null, "{$label}: hostSystem"),
            'hostCategory' => HostInput::hostToken($input['hostCategory'] ?? null, "{$label}: hostCategory"),
        ];
        if ($method === PurgeMethod::ANONYMIZED) {
            $body['anonymizationProcessKey'] = $processKey;
        }
        if ($references !== null) {
            $body['references'] = $references;
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/retention/rules/' . rawurlencode($key) . '/purges',
            body: $body,
            headers: ['Idempotency-Key' => $idempotencyKey],
            idempotentRetry: false,
        ));
        return PurgeDeclaration::fromWire($wire);
    }

    /**
     * DECLARE a pass under a rule that found NOTHING to purge: the heartbeat that tells
     * a host with nothing to purge apart from a host that stopped purging. It records
     * what the host SAID, never that nothing was due.
     *
     * THE FLOOR: one pass per (rule, hostSystem) every 15 minutes. A second one is
     * AgreelySweepTooFrequentError (a typed 429 carrying Retry-After), which the SDK
     * NEVER auto-retries. Do not loop on it: the pass already declared stands for this
     * one. Two systems sharing a rule never block each other.
     *
     * ⚠️ `sweptAt` must fall within the last 24 hours, and an older one is refused
     * client-side because the server would refuse it too. That is the case nobody
     * guesses: a queued retry from yesterday, or a cron on a skewed clock, fails
     * remotely for a reason the logs do not explain.
     *
     * @param array{sweptAt:\DateTimeInterface|string,hostSystem:string} $input
     * @param array{idempotencyKey:string} $options
     */
    public function declareSweep(string $ruleKey, array $input, array $options): RetentionSweep
    {
        $label = 'retention.declareSweep';
        $key = HostInput::pathKey($ruleKey, $label, 'ruleKey');
        HostInput::closed($input, self::SWEEP_MEMBERS, $label);
        $idempotencyKey = HostInput::idempotencyKey($options, $label);

        $sweptAt = HostInput::instant($input['sweptAt'] ?? null, "{$label}: sweptAt");
        if ((microtime(true) * 1000.0) - $sweptAt['ms'] > (float) self::SWEEP_MAX_AGE_MS) {
            throw new AgreelyConfigError(
                "{$label}: sweptAt must fall within the last 24 hours. A pass older than that can no longer be "
                . 'declared, so a queued retry from yesterday is dropped rather than sent to a sure refusal.',
            );
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/retention/rules/' . rawurlencode($key) . '/sweeps',
            body: [
                'sweptAt' => $sweptAt['wire'],
                'hostSystem' => HostInput::hostToken($input['hostSystem'] ?? null, "{$label}: hostSystem"),
            ],
            headers: ['Idempotency-Key' => $idempotencyKey],
            idempotentRetry: false,
        ));
        return RetentionSweep::fromWire($wire);
    }

    /**
     * Bring a snapshot up to date. With a snapshot, only the rules changed since its
     * cursor are read, merged over the stored ones, and every rule whose key left
     * `ruleKeys` is dropped. A REFUSED cursor, or a current key the merge cannot
     * account for, falls back to one full read rather than guessing.
     */
    private function sync(?RetentionRuleSnapshot $snapshot): RetentionRuleSnapshot
    {
        if ($snapshot !== null && $snapshot->cursor !== '') {
            $changed = null;
            try {
                $changed = $this->listRules(['changedSince' => $snapshot->cursor]);
            } catch (AgreelyValidationError) {
                // A cursor the server refuses (malformed, or from a future it will not
                // accept). Fall back to a full read rather than acting on a partial set.
            }
            if ($changed !== null) {
                $merged = $this->merge($snapshot->rules, $changed);
                if ($merged !== null) {
                    return new RetentionRuleSnapshot($changed->cursor, self::now(), $merged);
                }
            }
        }
        $full = $this->listRules();
        return new RetentionRuleSnapshot($full->cursor, self::now(), $full->rules);
    }

    /**
     * The stored rules with the changed ones written over them, in the order of the
     * server's COMPLETE key set. Returns null when a current key is missing from both
     * sides, because the merge cannot account for it and a full read is the only honest
     * answer.
     *
     * @param list<RetentionRule> $stored
     * @return list<RetentionRule>|null
     */
    private function merge(array $stored, RetentionRuleList $changed): ?array
    {
        $byKey = [];
        foreach ($stored as $rule) {
            $byKey[$rule->key] = $rule;
        }
        foreach ($changed->rules as $rule) {
            $byKey[$rule->key] = $rule;
        }
        $rules = [];
        foreach ($changed->ruleKeys as $key) {
            if (!isset($byKey[$key])) {
                return null;
            }
            $rules[] = $byKey[$key];
        }
        return $rules;
    }

    /** `recordsAffected`: a whole number from 1 to 1,000,000,000. Zero is a sweep. */
    private function records(mixed $value, string $label): int
    {
        if (!is_int($value) || $value > self::PURGE_RECORDS_MAX) {
            throw new AgreelyConfigError(
                "{$label}: recordsAffected must be a whole number from 1 to " . self::PURGE_RECORDS_MAX . '.',
            );
        }
        if ($value < 1) {
            throw new AgreelyConfigError(
                "{$label}: recordsAffected must be at least 1. A pass that found nothing to purge is a pass, not a "
                . 'purge of zero records: call retention.declareSweep instead.',
            );
        }
        return $value;
    }

    /**
     * The host's own record identifiers, when given: a non-empty list of strings, at
     * most 1000, and never more than `recordsAffected`.
     *
     * @return list<string>|null
     */
    private function references(mixed $value, int $records, string $label): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new AgreelyConfigError(
                "{$label}: references, when given, is a non-empty list. Omit it otherwise.",
            );
        }
        if (count($value) > self::PURGE_REFERENCES_MAX) {
            throw new AgreelyConfigError(
                "{$label}: at most " . self::PURGE_REFERENCES_MAX . ' references per declaration. Declare a larger '
                . 'purge in several declarations, each with its own idempotencyKey.',
            );
        }
        if (count($value) > $records) {
            throw new AgreelyConfigError("{$label}: references cannot outnumber recordsAffected.");
        }
        $out = [];
        foreach ($value as $i => $reference) {
            if (!is_string($reference)) {
                throw new AgreelyConfigError("{$label}: references[{$i}] must be a string.");
            }
            $out[] = $reference;
        }
        return $out;
    }

    /** The local clock, as the RFC 3339 UTC instant a snapshot records. */
    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
