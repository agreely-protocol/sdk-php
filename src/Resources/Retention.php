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
use Agreely\Sdk\IdempotencyKey;
use Agreely\Sdk\Types\CustomerRetention;
use Agreely\Sdk\Types\DeclaredDisposition;
use Agreely\Sdk\Types\DispositionKind;
use Agreely\Sdk\Types\HoldGround;
use Agreely\Sdk\Types\HoldsSync;
use Agreely\Sdk\Types\PlacedHold;
use Agreely\Sdk\Types\PurgeDeclaration;
use Agreely\Sdk\Types\ReleasedHold;
use Agreely\Sdk\Types\RetentionHoldPage;
use Agreely\Sdk\Types\PurgeMethod;
use Agreely\Sdk\Types\RetentionRule;
use Agreely\Sdk\Types\RetentionRuleDetail;
use Agreely\Sdk\Types\RetentionRuleList;
use Agreely\Sdk\Types\RetentionRuleSnapshot;
use Agreely\Sdk\Types\RetentionSweep;
use Agreely\Sdk\Types\RulesForPurge;
use Generator;

/**
 * The host-retention resource. THREE SCOPES, one per kind of call:
 *   'retention'  the decided rules, and the purges and passes a host system declares
 *   'holds'      the feed of the retention holds in place (listHolds, holdPages, syncHolds)
 *   'registry'   ONE customer's posture, dispositions and holds (getCustomerRetention,
 *                declareDisposition, placeHold, releaseHold)
 *
 * « Agreely décide et surveille, l'hôte exécute et rend compte. » Agreely DECIDES the
 * rules and RECORDS what the host declares; the host reads the decisions, runs its
 * purges, and DECLARES them. Agreely observes nothing in the host's systems and
 * verifies none of it: every write answers `status: "declared"`, never "verified".
 *
 * No write here is ever auto-retried, and every Idempotency-Key travels in the $options
 * argument, because it is a HEADER: putting it in the body is refused client-side rather
 * than silently dropped ({@see HostInput::closed()}). The purge and pass declarations
 * REQUIRE one, and the digest the server stores is BOUND TO THE API KEY that declared:
 * replaying a declaration after rotating the key records a SECOND declaration, so settle
 * every pending declaration with the old key before revoking it. placeHold() and
 * releaseHold() generate one per call unless you pass your own.
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

    /** The holds feed pages one sync reads before it refuses to go on. */
    public const HOLDS_MAX_PAGES = 1000;

    /** The free-text bound of a hold provision, a disposition reason and a release reason. */
    public const HOLD_TEXT_MAX = 2000;

    /** The bound of a disposition's conservation-rule reference. */
    public const SCHEDULE_REF_MAX = 200;

    /** The members a disposition accepts, and no others. */
    private const DISPOSITION_MEMBERS = ['disposition', 'reason', 'retentionUntil', 'scheduleRef'];

    /** The members a hold accepts, and no others: a host never links a rights request. */
    private const HOLD_MEMBERS = ['ground', 'provision', 'scope', 'startedOn', 'reviewOn'];

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
        HostInput::closed($options, ['idempotencyKey'], $label);
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
        HostInput::closed($options, ['idempotencyKey'], $label);
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
     * ONE PAGE of the retention-hold feed (GET /v1/retention/holds, scope 'holds', never
     * granted by default: a purge job's key carries 'retention' AND 'holds').
     *
     * What a purge job reads before it purges anything: REFERENCES AND SCOPE, never why.
     *   no changedSince  a SNAPSHOT: every ACTIVE hold, which REPLACES your whole active set
     *   changedSince     a DELTA: every hold placed or released since, each with its status
     * Pages hold at most 500 rows. Pass `nextPageToken` back as pageToken, unchanged, until
     * a page answers `cursor`: that is the next sync's changedSince. A pageToken carries its
     * own changedSince, so one sent beside it is ignored by the server.
     *
     * Most callers want {@see Retention::syncHolds()}, which follows the pages.
     *
     * @param array{changedSince?:string|null,pageToken?:string|null} $input
     */
    public function listHolds(array $input = []): RetentionHoldPage
    {
        $label = 'retention.listHolds';
        HostInput::closed($input, ['changedSince', 'pageToken'], $label);
        foreach (['changedSince', 'pageToken'] as $name) {
            if (isset($input[$name]) && !is_string($input[$name])) {
                throw new AgreelyConfigError("{$label}: \"{$name}\" must be a string.");
            }
        }
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/retention/holds',
            query: [
                'changedSince' => $input['changedSince'] ?? null,
                'pageToken' => $input['pageToken'] ?? null,
            ],
            idempotentRetry: true,
        ));
        return RetentionHoldPage::fromWire($wire);
    }

    /**
     * Every PAGE of one sync, following `nextPageToken` for you, as a Generator, for a job
     * that streams the held set into its own store. The LAST page carries `cursor`.
     * Bounded by `maxPages` (default 1000 pages, 500,000 holds).
     *
     * 🔴 It THROWS AgreelyConfigError, after the pages already yielded, when the feed does
     * not reach its last page within `maxPages` or when a last page carries no cursor, and
     * any API error throws too. So record what a page holds as you go, but purge NOTHING
     * until the loop has ended without a throw. Most jobs want
     * {@see Retention::syncHolds()}, which collects the pages first.
     *
     *   foreach ($agreely->retention()->holdPages(['changedSince' => $stored]) as $page) { ... }
     *
     * @param array{changedSince?:string|null,maxPages?:int} $input
     * @return Generator<int, RetentionHoldPage>
     */
    public function holdPages(array $input = []): Generator
    {
        $label = 'retention.holdPages';
        HostInput::closed($input, ['changedSince', 'maxPages'], $label);
        $maxPages = isset($input['maxPages']) && is_int($input['maxPages']) && $input['maxPages'] > 0
            ? $input['maxPages']
            : self::HOLDS_MAX_PAGES;
        $changedSince = self::changedSince($input, $label);
        $pageToken = null;
        for ($page = 0; $page < $maxPages; $page++) {
            $result = $this->listHolds($pageToken === null
                ? ['changedSince' => $changedSince]
                : ['pageToken' => $pageToken]);
            if ($result->isLast() && ($result->cursor === null || $result->cursor === '')) {
                // Neither a next page nor a cursor: the feed did not say it ended.
                throw self::partialFeed($label);
            }
            yield $result;
            if ($result->isLast()) {
                return;
            }
            $pageToken = $result->nextPageToken;
        }
        throw self::partialFeed($label);
    }

    /**
     * ONE COMPLETE SYNC of the holds feed: every page read and collected, with the
     * `cursor` to persist for the next sync ({@see HoldsSync}).
     *
     *   mode "snapshot" (no changedSince) REPLACES your whole active set
     *   mode "delta"    (changedSince)    every hold placed or released since, with its status
     * Delivery is AT LEAST ONCE: upsert by id. An empty changedSince is a snapshot.
     *
     * 🔴 FAIL CLOSED: purge only after this returns. Any error (401, 403, 402, a 5xx, a
     * timeout) THROWS, and a purge job treats it as "do not purge this run" and stores no
     * cursor. A feed that does not reach its last page within `maxPages`, or whose last
     * page carries no cursor, throws AgreelyConfigError rather than return a partial
     * feed: an incomplete held set read as complete would let a purge destroy what a hold
     * keeps.
     *
     * @param array{changedSince?:string|null,maxPages?:int} $input
     */
    public function syncHolds(array $input = []): HoldsSync
    {
        $mode = self::changedSince($input, 'retention.syncHolds') === null ? HoldsSync::MODE_SNAPSHOT : HoldsSync::MODE_DELTA;
        $holds = [];
        $cursor = '';
        foreach ($this->holdPages($input) as $page) {
            foreach ($page->holds as $hold) {
                $holds[] = $hold;
            }
            $cursor = (string) $page->cursor;
        }
        return new HoldsSync($mode, $holds, $cursor);
    }

    /**
     * One customer's retention posture (GET /v1/customers/{customerRef}/retention, scope
     * 'registry'): the
     * relationship, the DERIVED clock with its recipe, the standing disposition and the
     * holds ({@see CustomerRetention}).
     *
     * The top-level `clock->dueAt` is the earliest horizon of a rule NO hold suspends; a
     * rule a hold covers says `held` and its date is arithmetic, not a date to act on.
     * The clock never invents a period: with no declared rule it is null with reason
     * "no_declared_rule".
     */
    public function getCustomerRetention(string $customerRef): CustomerRetention
    {
        $ref = HostInput::customerRef($customerRef, 'retention.getCustomerRetention');
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/customers/' . rawurlencode($ref) . '/retention',
            idempotentRetry: true,
        ));
        return CustomerRetention::fromWire($wire);
    }

    /**
     * DECLARE what your system did with one customer's information once the relationship
     * ENDED (POST /v1/customers/{customerRef}/retention/dispositions, scope 'registry'): "destroyed",
     * "anonymized" or "legal_hold" ({@see DispositionKind}).
     *
     * - reason          REQUIRED for legal_hold: name the law that imposes the delay. At
     *                   most 2000 characters, encrypted, never echoed.
     * - retentionUntil  legal_hold only, YYYY-MM-DD.
     * - scheduleRef     optional, the conservation rule it was made under (a public body's
     *                   approved calendrier rule), at most 200 characters, never verified.
     *
     * APPEND-ONLY: a correction is a superseding declaration. Re-declaring the one that
     * STANDS replays it (`appended` false). A relationship that has not ended is a 409
     * AgreelyConflictError code relationship_active: end it first with
     * relationships()->end(), or place a hold to keep information while it runs. A
     * declaration made while a hold is in place is recorded, with a `hold_active` warning.
     *
     * 🔴 IRREVERSIBLE SIDE EFFECT. An appended destroyed or anonymized declaration removes
     * the name, email, basis note and notice language Agreely holds for this customer, in
     * the same transaction, unless an active hold on ALL the information keeps them. Read
     * `agreelyIdentity` on the answer ({@see \Agreely\Sdk\Types\AgreelyIdentityOutcome}).
     *
     * NEVER auto-retried.
     *
     * @param array{disposition:string,reason?:string,retentionUntil?:string,scheduleRef?:string} $input
     */
    public function declareDisposition(string $customerRef, array $input): DeclaredDisposition
    {
        $label = 'retention.declareDisposition';
        $ref = HostInput::customerRef($customerRef, $label);
        HostInput::closed($input, self::DISPOSITION_MEMBERS, $label);

        $disposition = $input['disposition'] ?? null;
        if (!is_string($disposition) || !in_array($disposition, DispositionKind::ALL, true)) {
            throw new AgreelyConfigError("{$label}: disposition must be one of " . implode(', ', DispositionKind::ALL) . '.');
        }
        $isHold = $disposition === DispositionKind::LEGAL_HOLD;
        $body = ['disposition' => $disposition];

        $reason = self::optionalText($input, 'reason', self::HOLD_TEXT_MAX, $label);
        if ($isHold && $reason === null) {
            throw new AgreelyConfigError(
                "{$label}: a \"legal_hold\" requires a reason naming the law that imposes the retention delay.",
            );
        }
        if ($reason !== null) {
            $body['reason'] = $reason;
        }
        if (($input['retentionUntil'] ?? null) !== null) {
            if (!$isHold) {
                throw new AgreelyConfigError(
                    "{$label}: retentionUntil applies only to a \"legal_hold\": a destroyed or anonymized record has "
                    . 'no remaining conservation horizon.',
                );
            }
            $body['retentionUntil'] = HostInput::calendarDay($input['retentionUntil'], "{$label}: retentionUntil");
        }
        $scheduleRef = self::optionalText($input, 'scheduleRef', self::SCHEDULE_REF_MAX, $label);
        if ($scheduleRef !== null) {
            $body['scheduleRef'] = $scheduleRef;
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/customers/' . rawurlencode($ref) . '/retention/dispositions',
            body: $body,
            idempotentRetry: false,
        ));
        return DeclaredDisposition::fromWire($wire);
    }

    /**
     * PLACE a retention hold on one customer (POST /v1/customers/{customerRef}/retention/holds,
     * scope 'registry'): their information must be kept despite the retention rules, on a
     * live or an ended relationship.
     *
     * - ground     REQUIRED: "rights_request" (the information is the subject of an access
     *              or rectification request, P-39.1 s. 36 / A-2.1 s. 102.1) or "other_law".
     * - provision  REQUIRED for "other_law", refused otherwise: the provision of the law
     *              that requires the keeping, at most 2000 characters, never verified.
     * - scope      "all" (the default), or ['rules' => [...], 'cells' => [...]] with keys
     *              from retention()->listRules() and catalog()->listCells().
     * - startedOn  optional YYYY-MM-DD, today or earlier on the organisation's calendar;
     *              defaults to today.
     * - reviewOn   optional YYYY-MM-DD, a REMINDER only: nothing ever releases a hold on it.
     *
     * A host never links a rights request; the rights register places its own holds.
     * At most 50 active holds per customer (422 code too_many_holds) and 1000 holds
     * placed through /v1 per organisation per 24 hours (429 AgreelyDailyCapError code
     * hold_budget_exhausted). An Idempotency-Key is generated per call unless you pass
     * one: the same key and body replays the first answer (`replayed` true). NEVER
     * auto-retried.
     *
     * @param array{ground:string,provision?:string,scope?:string|array{rules?:list<string>,cells?:list<string>},startedOn?:string,reviewOn?:string} $input
     * @param array{idempotencyKey?:string} $options
     */
    public function placeHold(string $customerRef, array $input, array $options = []): PlacedHold
    {
        $label = 'retention.placeHold';
        $ref = HostInput::customerRef($customerRef, $label);
        HostInput::closed($input, self::HOLD_MEMBERS, $label);
        HostInput::closed($options, ['idempotencyKey'], $label);

        $ground = $input['ground'] ?? null;
        if (!is_string($ground) || !in_array($ground, HoldGround::ALL, true)) {
            throw new AgreelyConfigError("{$label}: ground must be \"rights_request\" or \"other_law\".");
        }
        $body = ['ground' => $ground];

        $provision = self::optionalText($input, 'provision', self::HOLD_TEXT_MAX, $label);
        if ($ground === HoldGround::OTHER_LAW && $provision === null) {
            throw new AgreelyConfigError(
                "{$label}: an \"other_law\" hold requires a provision naming the law that requires the information "
                . 'to be kept.',
            );
        }
        if ($ground === HoldGround::RIGHTS_REQUEST && $provision !== null) {
            throw new AgreelyConfigError("{$label}: a provision applies only to an \"other_law\" hold.");
        }
        if ($provision !== null) {
            $body['provision'] = $provision;
        }
        if (array_key_exists('scope', $input) && $input['scope'] !== null) {
            $body['scope'] = self::holdScope($input['scope'], $label);
        }
        foreach (['startedOn', 'reviewOn'] as $day) {
            if (($input[$day] ?? null) !== null) {
                $body[$day] = HostInput::calendarDay($input[$day], "{$label}: {$day}");
            }
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/customers/' . rawurlencode($ref) . '/retention/holds',
            body: $body,
            headers: ['Idempotency-Key' => IdempotencyKey::resolve($options, $label)],
            idempotentRetry: false,
        ));
        return PlacedHold::fromWire($wire);
    }

    /**
     * RELEASE one active hold (POST /v1/customers/{customerRef}/retention/holds/{holdId}/release,
     * scope 'registry'), whoever placed it, with a REQUIRED `reason` (at most 2000 characters, encrypted,
     * never echoed). Releasing is the organisation's decision: Agreely never computes
     * when a hold may end and does not verify that a person's recourses are exhausted.
     *
     * A hold your system did not place (`placedBy` "organization") counts against a
     * rolling 24-hour cap (20 unless the operator set another value; 429
     * AgreelyDailyCapError code hold_release_cap_reached), and the workspace owner is
     * emailed. An already released hold is a 409 AgreelyConflictError code
     * already_released. An unknown or another customer's hold id is AgreelyNotFoundError.
     *
     * 🔴 IRREVERSIBLE SIDE EFFECT. Releasing the last hold on ALL the information while a
     * destroyed or anonymized declaration stands removes the name, email, basis note and
     * notice language Agreely holds: `agreelyIdentity` is then "erased".
     *
     * An Idempotency-Key is generated per call unless you pass one. NEVER auto-retried.
     *
     * @param array{reason:string} $input
     * @param array{idempotencyKey?:string} $options
     */
    public function releaseHold(string $customerRef, string $holdId, array $input, array $options = []): ReleasedHold
    {
        $label = 'retention.releaseHold';
        $ref = HostInput::customerRef($customerRef, $label);
        $hold = HostInput::uuid($holdId, "{$label}: holdId");
        HostInput::closed($input, ['reason'], $label);
        HostInput::closed($options, ['idempotencyKey'], $label);
        $reason = self::optionalText($input, 'reason', self::HOLD_TEXT_MAX, $label);
        if ($reason === null) {
            throw new AgreelyConfigError("{$label} requires a reason: lifting a hold is a motivated act.");
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/customers/' . rawurlencode($ref) . '/retention/holds/' . rawurlencode($hold) . '/release',
            body: ['reason' => $reason],
            headers: ['Idempotency-Key' => IdempotencyKey::resolve($options, $label)],
            idempotentRetry: false,
        ));
        return ReleasedHold::fromWire($wire);
    }

    /**
     * An optional free-text member: null when absent, null or blank; else the string,
     * refused over $max characters (the server refuses rather than truncates, so a
     * shortened justification never records a ground it does not carry).
     *
     * @param array<array-key,mixed> $input
     */
    private static function optionalText(array $input, string $name, int $max, string $label): ?string
    {
        $value = $input[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new AgreelyConfigError("{$label}: {$name} must be a string.");
        }
        if (trim($value) === '') {
            return null;
        }
        if (mb_strlen(trim($value)) > $max) {
            throw new AgreelyConfigError("{$label}: {$name} must be at most {$max} characters; it is never truncated.");
        }
        return $value;
    }

    /**
     * A hold scope: "all", or a closed {rules, cells} object of string lists, at least one
     * of them non-empty.
     *
     * @return string|array{rules?:list<string>,cells?:list<string>}
     */
    private static function holdScope(mixed $scope, string $label): string|array
    {
        if ($scope === 'all') {
            return 'all';
        }
        if (!is_array($scope) || ($scope !== [] && array_is_list($scope))) {
            throw new AgreelyConfigError(
                "{$label}: scope must be \"all\" or ['rules' => [...], 'cells' => [...]].",
            );
        }
        HostInput::closed($scope, ['rules', 'cells'], "{$label} scope");
        $out = [];
        foreach (['rules', 'cells'] as $key) {
            if (!array_key_exists($key, $scope)) {
                continue;
            }
            $list = $scope[$key];
            if (!is_array($list) || !array_is_list($list)) {
                throw new AgreelyConfigError("{$label}: scope.{$key} must be a list of keys.");
            }
            $keys = [];
            foreach ($list as $i => $id) {
                if (!is_string($id) || trim($id) === '') {
                    throw new AgreelyConfigError("{$label}: scope.{$key}[{$i}] must be a key string.");
                }
                $keys[] = $id;
            }
            $out[$key] = $keys;
        }
        if (($out['rules'] ?? []) === [] && ($out['cells'] ?? []) === []) {
            throw new AgreelyConfigError(
                "{$label}: scope must list at least one retention rule or catalogue cell, or be \"all\".",
            );
        }
        return $out;
    }

    /**
     * The sync's changedSince: null for a snapshot, an empty string included (the server
     * reads an empty one as absent, so it must not be reported as a delta). Anything but a
     * string or null is refused, never read as a snapshot.
     *
     * @param array<string,mixed> $input
     */
    private static function changedSince(array $input, string $label): ?string
    {
        $value = $input['changedSince'] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new AgreelyConfigError("{$label}: \"changedSince\" must be a string, the cursor of a previous sync.");
        }
        return $value === null || $value === '' ? null : $value;
    }

    /** The refusal of a feed that did not provably end: never act on a partial held set. */
    private static function partialFeed(string $label): AgreelyConfigError
    {
        return new AgreelyConfigError(
            "{$label}: the holds feed did not reach a last page carrying a cursor, so the held set is INCOMPLETE. "
            . 'Purge nothing this run; raise maxPages if your organisation genuinely holds that many.',
        );
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
