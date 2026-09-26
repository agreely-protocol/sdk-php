<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

use Agreely\Sdk\Errors\AgreelyConfigError;

/**
 * What a purge job may do with the rules for THIS run, from
 * {@see \Agreely\Sdk\Resources\Retention::rulesForPurge()}.
 *
 *   SOURCE_LIVE     read from Agreely now. Persist `snapshot` for the next run.
 *   SOURCE_SNAPSHOT Agreely was unreachable and you OPTED IN to purging on the
 *                   stored rules. `staleForMs` says how old they are.
 *   SOURCE_ABSTAIN  Agreely was unreachable and no usable snapshot applies. DO NOT
 *                   PURGE THIS RUN. This is the default outcome of an outage.
 *
 * 🔴 CHECK {@see RulesForPurge::mayPurge()} BEFORE READING THE RULES. On an abstain
 * {@see RulesForPurge::rules()} THROWS rather than handing back an empty list,
 * because an empty list reads as "nothing to purge" and would turn an outage into a
 * silent, successful-looking no-op that hides a missed run. The TS SDK gets this
 * from a discriminated union; in PHP it is a thrown error, and it is deliberate.
 */
final class RulesForPurge
{
    public const SOURCE_LIVE     = 'live';
    public const SOURCE_SNAPSHOT = 'snapshot';
    public const SOURCE_ABSTAIN  = 'abstain';

    /** Why the run abstained: the outage itself, no snapshot, or one too old to use. */
    public const REASON_OUTAGE           = 'outage';
    public const REASON_NO_SNAPSHOT      = 'no_snapshot';
    public const REASON_SNAPSHOT_TOO_OLD = 'snapshot_too_old';

    /** @param list<RetentionRule> $rules */
    private function __construct(
        public readonly string $source,
        private readonly array $rules,
        public readonly ?RetentionRuleSnapshot $snapshot,
        public readonly ?string $reason,
        public readonly ?float $staleForMs,
        public readonly ?\Throwable $error,
    ) {
    }

    /** The rules were read from Agreely on this run. */
    public static function live(RetentionRuleSnapshot $snapshot): self
    {
        return new self(self::SOURCE_LIVE, $snapshot->rules, $snapshot, null, null, null);
    }

    /** Agreely was unreachable and the caller opted into the stored rules. */
    public static function snapshot(RetentionRuleSnapshot $snapshot, float $staleForMs, \Throwable $error): self
    {
        return new self(self::SOURCE_SNAPSHOT, $snapshot->rules, $snapshot, null, $staleForMs, $error);
    }

    /** Agreely was unreachable and this run must not purge. */
    public static function abstain(string $reason, \Throwable $error): self
    {
        return new self(self::SOURCE_ABSTAIN, [], null, $reason, null, $error);
    }

    /**
     * Whether this run may purge at all. FALSE means abstain: skip the run, log the
     * reason, and try again next time.
     */
    public function mayPurge(): bool
    {
        return $this->source !== self::SOURCE_ABSTAIN;
    }

    /** Whether this run is proceeding on STORED rules rather than freshly read ones. */
    public function isDegraded(): bool
    {
        return $this->source === self::SOURCE_SNAPSHOT;
    }

    /**
     * The rules to act on. THROWS on an abstain: there are none, and silently
     * returning an empty list would make a missed run look like a clean one.
     *
     * @return list<RetentionRule>
     */
    public function rules(): array
    {
        if ($this->source === self::SOURCE_ABSTAIN) {
            throw new AgreelyConfigError(
                'RulesForPurge::rules() was read on an ABSTAIN (' . ($this->reason ?? 'outage') . '): '
                . 'Agreely could not be reached and no usable snapshot applies, so this run must not purge. '
                . 'Check mayPurge() first and skip the run.',
            );
        }
        return $this->rules;
    }
}
