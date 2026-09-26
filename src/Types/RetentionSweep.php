<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The stored pass: a host system declared it ran a pass under a rule and found
 * nothing to purge. The heartbeat that tells a host with nothing to purge apart
 * from a host that STOPPED purging.
 *
 * ⚠️ `declaredNothingDue` is what the host SAID, never a fact Agreely established,
 * and `status` is "declared", never "verified".
 */
final class RetentionSweep
{
    public function __construct(
        public readonly string $id,
        public readonly string $ruleKey,
        public readonly bool $recorded,
        public readonly string $status,
        public readonly bool $declaredNothingDue,
        public readonly bool $replayed,
        public readonly string $sweptAt,
        public readonly string $hostSystem,
        public readonly string $declaredBy,
        public readonly string $declaredAt,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['id'] ?? null),
            Wire::str($wire['ruleKey'] ?? null),
            Wire::bool($wire['recorded'] ?? null),
            Wire::str($wire['status'] ?? null),
            Wire::bool($wire['declaredNothingDue'] ?? null),
            Wire::bool($wire['replayed'] ?? null),
            Wire::str($wire['sweptAt'] ?? null),
            Wire::str($wire['hostSystem'] ?? null),
            Wire::str($wire['declaredBy'] ?? null),
            Wire::str($wire['declaredAt'] ?? null),
        );
    }
}
