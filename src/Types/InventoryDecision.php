<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * What AGREELY decided for a declared record set: the cell and the rule it was
 * linked to, or the named gap that says why there is no duration.
 *
 *   STATE_DECIDED the set is linked to a cell that has a rule
 *   STATE_NO_CELL the set is not linked to a catalogue cell
 *   STATE_NO_RULE it is linked, but that cell has no retention rule
 *
 * 🔴 ON EITHER GAP THE HOST ABSTAINS. It never invents a number, and the gap is
 * fixed in Agreely, not in the host's code.
 */
final class InventoryDecision
{
    public const STATE_DECIDED = 'decided';
    public const STATE_NO_CELL = 'no_cell';
    public const STATE_NO_RULE = 'no_rule';

    public function __construct(
        public readonly string $state,
        public readonly ?string $cellKey,
        public readonly ?string $ruleKey,
    ) {
    }

    /**
     * @param array<string,mixed> $wire the `decision` member of a record set
     */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['state'] ?? null),
            Wire::nullableStr($wire['cellKey'] ?? null),
            Wire::nullableStr($wire['ruleKey'] ?? null),
        );
    }

    /** Whether Agreely decided a duration for this set. False means the host abstains. */
    public function isDecided(): bool
    {
        return $this->state === self::STATE_DECIDED;
    }
}
