<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One declared "purpose_achieved" retention rule on a customer's clock: its period,
 * its action, its ARITHMETIC `dueAt` (a calendar date, null while the relationship
 * runs), and `held` when an active retention hold suspends it. A held rule's date is
 * arithmetic, never a date to act on.
 */
final class RetentionClockRule
{
    public function __construct(
        public readonly string $ruleId,
        public readonly string $label,
        public readonly ?string $labelEn,
        public readonly int $periodMonths,
        public readonly string $action,
        public readonly ?string $dueAt,
        public readonly bool $held,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['ruleId'] ?? null),
            Wire::str($wire['label'] ?? null),
            Wire::nullableStr($wire['labelEn'] ?? null),
            Wire::int($wire['periodMonths'] ?? null),
            Wire::str($wire['action'] ?? null),
            Wire::nullableStr($wire['dueAt'] ?? null),
            Wire::bool($wire['held'] ?? false),
        );
    }
}
