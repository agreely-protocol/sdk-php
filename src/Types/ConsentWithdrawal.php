<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The 200 body of POST /v1/customers/{customerRef}/consents/{consentRef}/withdrawal: a
 * person's withdrawal, RECORDED BY THE ORGANISATION on her behalf.
 *
 * Honest by construction: `recordedOnBehalf` is always true and `assurance` always
 * "company_documented", whatever the tier of the consent withdrawn. Agreely records that
 * the organisation says it received and actioned the request; it did not witness it,
 * and this is never the person's own signed revocation.
 *
 * `gate` says what /v1/check does NOW for the purpose, read after the act:
 *   GATE_DENIED     nothing holds the gate any more
 *   GATE_SUPERSEDED another consent for the purpose still holds it (one the person
 *                   signed online AFTER the declared requestedAt); the gate may STILL ALLOW
 *   GATE_UNCHANGED  an idempotent repeat (alreadyWithdrawn true, alsoWithdrawn empty)
 *
 * `alsoWithdrawn` lists the consentRefs of the consents the withdrawal carried with it at
 * the same instant: its own chain (a renewal and the paper it renewed, a verbal consent and
 * its confirming paper), and each other consent of the purpose the call declared
 * (otherConsents "withdraw", or alsoWithdraw). A withdrawal never ends another consent of
 * the purpose on its own: when one runs and the call did not say, the call is refused
 * (AgreelyOtherConsentsRunningError) and nothing is written.
 *
 * `stillInForce` lists the 0x consentRefs of the other consents of the purpose that are
 * still in force after the act, which it did not reach (declared "keep", or left out of
 * alsoWithdraw). Empty when none remains. Never read `withdrawn` as "the purpose is now
 * denied": read gate.
 */
final class ConsentWithdrawal
{
    public const GATE_DENIED     = 'denied';
    public const GATE_SUPERSEDED = 'superseded';
    public const GATE_UNCHANGED  = 'unchanged';

    /**
     * @param list<string> $alsoWithdrawn
     * @param list<string> $stillInForce
     */
    public function __construct(
        public readonly string $consentRef,
        public readonly bool $withdrawn,
        public readonly bool $alreadyWithdrawn,
        public readonly bool $recordedOnBehalf,
        public readonly string $assurance,
        public readonly string $gate,
        public readonly array $alsoWithdrawn,
        public readonly array $stillInForce = [],
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
            Wire::str($wire['assurance'] ?? null, Assurance::COMPANY_DOCUMENTED),
            Wire::str($wire['gate'] ?? null),
            Wire::strings($wire, 'alsoWithdrawn'),
            Wire::strings($wire, 'stillInForce'),
        );
    }

    /** True only when, after this withdrawal, nothing holds the gate for the purpose. */
    public function gateDenied(): bool
    {
        return $this->gate === self::GATE_DENIED;
    }
}
