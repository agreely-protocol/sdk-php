<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The 200 body from revoking a company-recorded (manual or verbal) consent cell. The
 * call is idempotent server-side.
 *
 * `gate` says what /v1/check does NOW for that purpose:
 *   GATE_DENIED     this consent backed the gate, and the gate now denies
 *   GATE_SUPERSEDED a later consent for the same purpose had already taken the gate
 *                   over; this withdrawal is recorded and that consent is untouched,
 *                   so the gate may STILL ALLOW
 *   GATE_UNCHANGED  an idempotent repeat (alreadyRevoked true)
 *
 * `revoked` is always true; never read it as "the purpose is now denied": read gate.
 */
final class ManualConsentRevocation
{
    public const GATE_DENIED     = 'denied';
    public const GATE_SUPERSEDED = 'superseded';
    public const GATE_UNCHANGED  = 'unchanged';

    public function __construct(
        public readonly string $consentRef,
        public readonly bool $revoked,
        public readonly bool $alreadyRevoked,
        public readonly string $gate = '',
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['consentRef'] ?? null),
            Wire::bool($wire['revoked'] ?? false),
            Wire::bool($wire['alreadyRevoked'] ?? false),
            Wire::str($wire['gate'] ?? null),
        );
    }

    /** True only when this withdrawal is what now makes the gate deny. */
    public function gateDenied(): bool
    {
        return $this->gate === self::GATE_DENIED;
    }
}
