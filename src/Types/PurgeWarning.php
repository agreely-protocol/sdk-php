<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A divergence Agreely FLAGS on a recorded declaration without refusing it. Today
 * the only code is `method_differs_from_rule`: the host declared it anonymized what
 * the rule says to destroy, or the reverse. The declaration is recorded as declared
 * and the divergence is surfaced.
 *
 * ⚠️ IT IS NEVER « non-compliant ». Agreely records what the host said and judges
 * nothing; the responsable reads the flag and decides.
 */
final class PurgeWarning
{
    public const METHOD_DIFFERS_FROM_RULE = 'method_differs_from_rule';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $ruleAction,
        public readonly string $declaredMethod,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['code'] ?? null),
            Wire::str($wire['message'] ?? null),
            Wire::str($wire['ruleAction'] ?? null),
            Wire::str($wire['declaredMethod'] ?? null),
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
