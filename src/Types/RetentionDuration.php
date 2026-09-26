<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A DECIDED retention period. On a rule it is never null: a holding with no rule
 * is a gap in the register, surfaced as a null `retentionRuleKey` on a catalogue
 * cell, and the host abstains rather than picks a number.
 *
 * `unit` is "months" today and is carried rather than assumed, so a later unit
 * cannot be read as months by an old integration.
 */
final class RetentionDuration
{
    public function __construct(
        public readonly int $value,
        public readonly string $unit,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $value = $wire['value'] ?? null;
        return new self(is_numeric($value) ? (int) $value : 0, Wire::str($wire['unit'] ?? null, 'months'));
    }

    /**
     * The duration of a wire member, or null when the member is absent or null (a
     * statement that is not decided carries none).
     *
     * @param array<string,mixed> $wire
     */
    public static function fromWireMember(array $wire, string $key): ?self
    {
        $member = $wire[$key] ?? null;
        if (!is_array($member)) {
            return null;
        }
        /** @var array<string,mixed> $member */
        return self::fromWire($member);
    }

    /** @return array{value:int,unit:string} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'unit' => $this->unit];
    }
}
