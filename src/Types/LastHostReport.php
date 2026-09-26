<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One of the instants in a rule's `lastReports`: what the host SAID, beside when
 * Agreely received it.
 *
 * `at` is the host's own instant (a purge's `ranAt`, a pass's `sweptAt`);
 * `declaredAt` is when the declaration reached Agreely.
 *
 * ⚠️ NEITHER IS A DUE DATE OR AN OVERDUE FLAG. The register is declarative and
 * computes neither; do not derive one here and present it as Agreely's.
 */
final class LastHostReport
{
    public function __construct(
        public readonly string $at,
        public readonly string $declaredAt,
    ) {
    }

    /**
     * The report under $key of a `lastReports` object, or null when the host has
     * declared none.
     *
     * @param array<string,mixed> $lastReports
     * @param 'ranAt'|'sweptAt' $instantMember the member holding the host's own instant
     */
    public static function fromWireMember(array $lastReports, string $key, string $instantMember): ?self
    {
        $member = $lastReports[$key] ?? null;
        if (!is_array($member)) {
            return null;
        }
        /** @var array<string,mixed> $member */
        return new self(
            Wire::str($member[$instantMember] ?? null),
            Wire::str($member['declaredAt'] ?? null),
        );
    }
}
