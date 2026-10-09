<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The full result of a check. `check()` returns the boolean; this is the reasoned
 * form. Mirrors the openapi CheckDecision shape.
 *
 *   decision  -> "allow" | "deny" (ALLOW is the only true)
 *   status    -> the resolved cell state; see {@see CheckStatus} for the full
 *                vocabulary and what each one means
 *   consentRef -> 0x-hex enforcement handle; present for every status BACKED BY A
 *                RECORD. Null for "none" (no record) and for "necessity" (the allow
 *                rests on the declared catalog basis, not on a signed consent)
 *   basis     -> the DECLARED non-consent lawful basis behind a "necessity" allow
 *                (see {@see CheckBasis}); null for every other status. Agreely
 *                records the company's declared basis, it does not certify its
 *                legal validity, and a necessity allow is NEVER a consent artifact
 *   degraded  -> true ONLY when synthesized by the local degrade policy on an
 *                outage (never set on a real server decision)
 *   mode      -> the degrade mode that produced a degraded allow ("fail-open")
 *   assurance -> the proof behind the consent ({@see Assurance}:
 *                "citizen_signed" | "company_attested" | "company_documented");
 *                null for "none"/"necessity", for an acknowledged informed line
 *                (active or withdrawn: it is no consent at any tier) and on a
 *                degraded result. Treat an unknown value as NOT acceptable
 *   tier      -> the same proof under its stored name ({@see ConsentTier}:
 *                "full" | "manual" | "verbal"); present exactly when `assurance` is.
 *                The HOST decides what each tier may unlock
 *   validUntil -> the end of the consent backing the answer, an ISO 8601 UTC instant
 *                ("2027-10-09T03:59:59Z"), on every status backed by a consent
 *                record (revoked, expired and relationship_ended included). Null when
 *                there is no consent record to date (none, necessity, the named
 *                refusals: the key is absent from the wire), on an acknowledged
 *                informed line, on a legacy consent recorded with no end, and on a
 *                degraded result. It is an UPPER BOUND, never a cache lease: a
 *                withdrawal ends the consent before it and reaches only a host that
 *                checks again, and a renewal moves it. Read it on every check
 *   revokedAt -> when the withdrawal took effect, an ISO 8601 UTC instant, on status
 *                "revoked" only; null otherwise
 */
final class CheckResult
{
    public function __construct(
        public readonly string $decision,
        public readonly string $status,
        public readonly ?string $consentRef,
        public readonly string $checkedAt,
        public readonly bool $degraded = false,
        public readonly ?string $mode = null,
        public readonly ?string $assurance = null,
        public readonly ?string $basis = null,
        public readonly ?string $tier = null,
        public readonly ?string $validUntil = null,
        public readonly ?string $revokedAt = null,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['decision'] ?? null),
            Wire::str($wire['status'] ?? null),
            Wire::nullableStr($wire['consentRef'] ?? null),
            Wire::str($wire['checkedAt'] ?? null),
            assurance: Wire::nullableStr($wire['assurance'] ?? null),
            basis: Wire::nullableStr($wire['basis'] ?? null),
            tier: Wire::nullableStr($wire['tier'] ?? null),
            validUntil: Wire::nullableStr($wire['validUntil'] ?? null),
            revokedAt: Wire::nullableStr($wire['revokedAt'] ?? null),
        );
    }

    /** True when the decision is an allow (the only true). */
    public function isAllow(): bool
    {
        return $this->decision === 'allow';
    }

    /**
     * True when this allow rests on a DECLARED NON-CONSENT basis (status
     * "necessity") rather than on a signed consent record. Such an allow carries a
     * `basis` and no consentRef/assurance, and it must never be presented to a
     * person or an auditor as "consented".
     */
    public function isNecessity(): bool
    {
        return $this->status === CheckStatus::NECESSITY;
    }
}
