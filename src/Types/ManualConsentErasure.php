<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The 200 body from erasing a company-recorded consent cell. The call is idempotent
 * server-side.
 *
 * `gate` says what /v1/check does NOW for that purpose, as on a revoke
 * ({@see ManualConsentRevocation}): "denied" when this cell, or a consent the erasure
 * withdrew with it, backed the gate; "superseded" when a later consent unrelated to it
 * holds the gate (the gate may STILL ALLOW); "unchanged" on an idempotent repeat.
 */
final class ManualConsentErasure
{
    /**
     * `stillInForce` is true only when the call declared "keep" past a consent the person
     * signed online for the purpose, which stays in force (the record ends, the consent does not).
     */
    public function __construct(
        public readonly string $consentRef,
        public readonly bool $erased,
        public readonly bool $alreadyErased,
        public readonly string $gate = '',
        public readonly bool $stillInForce = false,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['consentRef'] ?? null),
            Wire::bool($wire['erased'] ?? false),
            Wire::bool($wire['alreadyErased'] ?? false),
            Wire::str($wire['gate'] ?? null),
            Wire::bool($wire['stillInForce'] ?? false),
        );
    }

    /** True only when this erasure is what now makes the gate deny. */
    public function gateDenied(): bool
    {
        return $this->gate === ManualConsentRevocation::GATE_DENIED;
    }
}
