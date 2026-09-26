<?php

declare(strict_types=1);

namespace Agreely\Sdk\Degrade;

use Agreely\Sdk\Duration;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Types\RetentionRuleSnapshot;
use Agreely\Sdk\Types\RulesForPurge;

/**
 * THE OTHER DIRECTION OF FAILING CLOSED, and it is a decision rather than a default.
 *
 * For a consent CHECK, failing closed means DENYING ({@see DegradePolicy}). For a
 * purge job it means NOT PURGING, because the two ways stored rules can be wrong are
 * not symmetrical:
 *
 *   - a rule was SHORTENED and the job missed it: it keeps data somewhat too long.
 *     A minor lapse, corrected by the next run that reads the rules.
 *   - 🔴 a rule was LENGTHENED (a legal hold, an investigation, a litigation) and the
 *     job missed it: it DESTROYS what had to be kept, and THAT DOES NOT REPAIR.
 *
 * So an outage ABSTAINS by default. Purging on stored rules is the explicit, bounded,
 * audited exception, the same shape as the check's fail-open: an explicit word
 * ("use-snapshot"), a capped window (maxSnapshotAge), and a mandatory evidence sink.
 *
 * ⚠️ ONLY AN OUTAGE REACHES HERE. A 401/403, a 402, a 422 and a 429 are thrown by the
 * resource, because none of them means "Agreely is down" and treating them as one
 * would hide a key, billing or billing-lapse problem behind a skipped night.
 *
 * The options are validated BEFORE any wire call, so a misconfiguration surfaces on a
 * healthy night rather than during the outage.
 */
final class RetentionDegrade
{
    public const ON_OUTAGE_ABSTAIN      = 'abstain';
    public const ON_OUTAGE_USE_SNAPSHOT = 'use-snapshot';

    private function __construct()
    {
    }

    /**
     * Validate the outage options and return the accepted snapshot age in
     * milliseconds, or null when the run will simply abstain.
     *
     * @param array<string,mixed> $options
     */
    public static function window(array $options, int $maxWindowMs = Duration::DEFAULT_MAX_DEGRADE_WINDOW_MS): ?int
    {
        $onOutage = $options['onOutage'] ?? null;
        if ($onOutage === null || $onOutage === self::ON_OUTAGE_ABSTAIN) {
            return null;
        }
        if ($onOutage !== self::ON_OUTAGE_USE_SNAPSHOT) {
            throw new AgreelyConfigError(
                'rulesForPurge onOutage must be "' . self::ON_OUTAGE_ABSTAIN . '" or "'
                . self::ON_OUTAGE_USE_SNAPSHOT . '"; got "' . var_export($onOutage, true) . '".',
            );
        }
        if (!is_callable($options['onDegrade'] ?? null)) {
            throw new AgreelyConfigError(
                'rulesForPurge onDegrade is mandatory with onOutage "' . self::ON_OUTAGE_USE_SNAPSHOT
                . '": every run that purges on stored rules must leave evidence.',
            );
        }
        $maxSnapshotAge = $options['maxSnapshotAge'] ?? null;
        if (!is_string($maxSnapshotAge)) {
            throw new AgreelyConfigError(
                'rulesForPurge maxSnapshotAge is mandatory with onOutage "' . self::ON_OUTAGE_USE_SNAPSHOT
                . '": an unbounded degraded purge is exactly what must not be possible. Use a form like "26h".',
            );
        }
        return Duration::parseCapped($maxSnapshotAge, $maxWindowMs, 'rulesForPurge maxSnapshotAge');
    }

    /**
     * Decide a purge run whose rule read failed with an OUTAGE. Returns an abstain
     * unless the caller opted into "use-snapshot", a usable snapshot exists, and it is
     * no older than the window; that one branch emits the evidence record.
     *
     * @param array<string,mixed> $options
     * @param float|null $nowMs epoch milliseconds, injectable so the window is testable
     */
    public static function decide(
        array $options,
        ?int $maxSnapshotAgeMs,
        \Throwable $error,
        ?float $nowMs = null,
    ): RulesForPurge {
        if (($options['onOutage'] ?? null) !== self::ON_OUTAGE_USE_SNAPSHOT || $maxSnapshotAgeMs === null) {
            return RulesForPurge::abstain(RulesForPurge::REASON_OUTAGE, $error);
        }
        $snapshot = $options['snapshot'] ?? null;
        if (!$snapshot instanceof RetentionRuleSnapshot) {
            return RulesForPurge::abstain(RulesForPurge::REASON_NO_SNAPSHOT, $error);
        }
        $fetchedAtMs = $snapshot->fetchedAtMs();
        if ($fetchedAtMs === null) {
            return RulesForPurge::abstain(RulesForPurge::REASON_NO_SNAPSHOT, $error);
        }
        $now = $nowMs ?? (microtime(true) * 1000.0);
        $staleForMs = max(0.0, $now - $fetchedAtMs);
        if ($staleForMs > (float) $maxSnapshotAgeMs) {
            return RulesForPurge::abstain(RulesForPurge::REASON_SNAPSHOT_TOO_OLD, $error);
        }

        $onDegrade = $options['onDegrade'] ?? null;
        if (is_callable($onDegrade)) {
            $onDegrade(new RetentionDegradeContext(
                $snapshot->fetchedAt,
                $staleForMs,
                $error,
                gmdate('Y-m-d\TH:i:s\Z', (int) ($now / 1000)),
            ));
        }
        return RulesForPurge::snapshot($snapshot, $staleForMs, $error);
    }
}
