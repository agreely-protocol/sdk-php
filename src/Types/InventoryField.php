<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One field of a declared record set: its NAME, never a value.
 *
 * 🔴 FIELDS, NEVER VALUES. A host declares that a record set HAS a field called
 * « Date de naissance ». It never sends a date of birth. The server enforces that
 * rather than promising it: a label shaped like a value (an at sign, a run of five
 * digits, a calendar date) is refused, as is any member other than key / label /
 * labelEn.
 *
 * `labelEn` is null when no English twin was authored, and it is never a machine
 * translation.
 */
final class InventoryField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $labelEn,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['key'] ?? null),
            Wire::str($wire['label'] ?? null),
            Wire::nullableStr($wire['labelEn'] ?? null),
        );
    }

    /**
     * @param list<array<string,mixed>> $wire
     * @return list<self>
     */
    public static function listFromWire(array $wire): array
    {
        return array_map(static fn (array $w): self => self::fromWire($w), array_values($wire));
    }
}
