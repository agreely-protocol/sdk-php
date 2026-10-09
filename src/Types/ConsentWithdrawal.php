<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The 200 body of POST /v1/customers/{customerRef}/consents/{consentRef}/withdrawal: a
 * person's withdrawal, RECORDED BY THE ORGANISATION on her behalf.
 *
 * Honest by construction: `recordedOnBehalf` is always true and `assurance` always
 * "company_attested", whatever the tier of the consent withdrawn. Agreely records that
 * the organisation says it received and actioned the request; it did not witness it,
 * and this is never the person's own signed revocation.
 *
 * `gate` says what /v1/check does NOW for the purpose, read after the act:
 *   GATE_DENIED     nothing holds the gate any more
 *   GATE_SUPERSEDED another consent for the purpose still holds it (one the person
 *                   signed online AFTER the declared requestedAt); the gate may STILL ALLOW
 *   GATE_UNCHANGED  an idempotent repeat (alreadyWithdrawn true, alsoWithdrawn empty)
 *
 * `alsoWithdrawn` lists the consentRefs of the other consents of the same purpose the
 * withdrawal was carried to at the same instant: a withdrawal attaches to the purpose,
 * never to one record. Never read `withdrawn` as "the purpose is now denied": read gate.
 */
final class ConsentWithdrawal
{
    public const GATE_DENIED     = 'denied';
    public const GATE_SUPERSEDED = 'superseded';
    public const GATE_UNCHANGED  = 'unchanged';

    /** @param list<string> $alsoWithdrawn */
    public function __construct(
        public readonly string $consentRef,
        public readonly bool $withdrawn,
        public readonly bool $alreadyWithdrawn,
        public readonly bool $recordedOnBehalf,
        public readonly string $assurance,
        public readonly string $gate,
        public readonly array $alsoWithdrawn,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['consentRef'] ?? null),
            Wire::bool($wire['withdrawn'] ?? false),
            Wire::bool($wire['alreadyWithdrawn'] ?? false),
            Wire::bool($wire['recordedOnBehalf'] ?? true),
            Wire::str($wire['assurance'] ?? null, Assurance::COMPANY_ATTESTED),
            Wire::str($wire['gate'] ?? null),
            Wire::strings($wire, 'alsoWithdrawn'),
        );
    }

    /** True only when, after this withdrawal, nothing holds the gate for the purpose. */
    public function gateDenied(): bool
    {
        return $this->gate === self::GATE_DENIED;
    }
}
