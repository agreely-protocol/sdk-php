<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A fact Agreely FLAGS on a recorded disposition without refusing it. Today the only
 * code is `hold_active`: a retention hold was in place on the customer when the
 * disposition was declared; `holdIds` names those holds. Recorded as received, never
 * refused, and never « non-compliant »: the responsable reads it and decides.
 */
final class DispositionWarning
{
    public const HOLD_ACTIVE = 'hold_active';

    /** @param list<string> $holdIds */
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $holdIds,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['code'] ?? null),
            Wire::str($wire['message'] ?? null),
            Wire::strings($wire, 'holdIds'),
        );
    }
}
