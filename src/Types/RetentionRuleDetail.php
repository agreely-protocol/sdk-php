<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The body of GET /v1/retention/rules/{ruleKey}: the rule, plus the instants of the
 * host's last declared purge and pass under it.
 *
 * The rule itself is reachable as `$detail->rule` (the SDK keeps
 * {@see RetentionRule} final, so the detail composes it rather than extending it).
 *
 * ⚠️ NOTHING HERE IS AN OVERDUE FLAG OR A NEXT DUE DATE. Both are absent because the
 * declarative register computes neither: a null `lastPurge` means the host has
 * declared no purge, not that none was due.
 */
final class RetentionRuleDetail
{
    public function __construct(
        public readonly RetentionRule $rule,
        public readonly ?LastHostReport $lastPurge,
        public readonly ?LastHostReport $lastSweep,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $reports = $wire['lastReports'] ?? null;
        /** @var array<string,mixed> $reports */
        $reports = is_array($reports) ? $reports : [];
        return new self(
            RetentionRule::fromWire($wire),
            LastHostReport::fromWireMember($reports, 'purge', 'ranAt'),
            LastHostReport::fromWireMember($reports, 'sweep', 'sweptAt'),
        );
    }
}
