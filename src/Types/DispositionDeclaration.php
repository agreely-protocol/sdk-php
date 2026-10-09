<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The answer to declaring a disposition: `recorded` true and `status` "declared", and
 * NOTHING STRONGER. Agreely recorded a declaration; it did not observe, confirm or
 * verify a destruction.
 *
 * `appended` is false when an identical declaration was already STANDING and was
 * replayed unchanged (200) instead of written twice. `declaration` is the ledger row,
 * read back. `warnings` flags what was recorded as received (a hold in place).
 */
final class DispositionDeclaration
{
    /** @param list<DispositionWarning> $warnings */
    public function __construct(
        public readonly string $customerRef,
        public readonly bool $recorded,
        public readonly string $status,
        public readonly bool $appended,
        public readonly Disposition $declaration,
        public readonly array $warnings,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['customerRef'] ?? null),
            Wire::bool($wire['recorded'] ?? false),
            Wire::str($wire['status'] ?? null),
            Wire::bool($wire['appended'] ?? false),
            Disposition::fromWire($wire),
            array_map(DispositionWarning::fromWire(...), Wire::objects($wire, 'warnings')),
        );
    }
}
