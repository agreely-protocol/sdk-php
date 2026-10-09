<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A customer's DERIVED art. 23 / A-2.1 art. 73 clock, shipped WITH its recipe so a host
 * recomputes the date rather than trusts it.
 *
 * `dueAt` is the EARLIEST horizon of a rule no hold suspends (a calendar date). When it
 * is null, `reason` says which "nothing" it is, and they are different facts:
 *   REASON_NO_DECLARED_RULE     no "purpose_achieved" rule is declared. The clock NEVER
 *                               invents a period: no defaulted 12 or 24 months.
 *   REASON_RELATIONSHIP_ACTIVE  the relationship has not ended, so there is no anchor yet
 *   REASON_HOLD_ACTIVE          every rule is held by an active retention hold
 */
final class RetentionClock
{
    public const REASON_NO_DECLARED_RULE    = 'no_declared_rule';
    public const REASON_RELATIONSHIP_ACTIVE = 'relationship_active';
    public const REASON_HOLD_ACTIVE         = 'hold_active';

    /** @param list<RetentionClockRule> $rules */
    public function __construct(
        public readonly string $trigger,
        public readonly string $recipe,
        public readonly ?string $dueAt,
        public readonly ?string $reason,
        public readonly array $rules,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['trigger'] ?? null),
            Wire::str($wire['recipe'] ?? null),
            Wire::nullableStr($wire['dueAt'] ?? null),
            Wire::nullableStr($wire['reason'] ?? null),
            array_map(RetentionClockRule::fromWire(...), Wire::objects($wire, 'rules')),
        );
    }
}
