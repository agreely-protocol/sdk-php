<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The answer to placing a retention hold: the hold as recorded. `replayed` is true when
 * the same Idempotency-Key with the same body replayed the first answer (200) instead of
 * placing a second hold (201).
 */
final class PlacedHold
{
    public function __construct(
        public readonly string $customerRef,
        public readonly bool $recorded,
        public readonly bool $replayed,
        public readonly RetentionHold $hold,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['customerRef'] ?? null),
            Wire::bool($wire['recorded'] ?? false),
            Wire::bool($wire['replayed'] ?? false),
            RetentionHold::fromWire($wire),
        );
    }
}
