<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The decision for one item in a POST /v1/check/batch response. Mirrors the openapi
 * BatchDecision shape; the same decision vocabulary as {@see CheckResult}, plus the
 * echoed (customerRef, category, purpose) for correlation.
 *
 * `basis` carries the DECLARED non-consent lawful basis behind a "necessity" allow
 * (see {@see CheckBasis}) and is null for every other status. `assurance` and
 * `tier` carry the proof behind a record-backed answer ({@see Assurance},
 * {@see ConsentTier}) and are null otherwise, including for an acknowledged
 * informed line. Treat an unknown assurance or tier as NOT acceptable.
 *
 * `validUntil` (the backing consent's end) and `revokedAt` (the withdrawal instant, on
 * "revoked" only) mean exactly what they mean on {@see CheckResult}: null when there is
 * no consent record to date.
 */
final class BatchDecision
{
    public function __construct(
        public readonly string $customerRef,
        public readonly string $category,
        public readonly string $purpose,
        public readonly string $decision,
        public readonly string $status,
        public readonly ?string $consentRef,
        public readonly ?string $assurance,
        public readonly string $checkedAt,
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
            Wire::str($wire['customerRef'] ?? null),
            Wire::str($wire['category'] ?? null),
            Wire::str($wire['purpose'] ?? null),
            Wire::str($wire['decision'] ?? null),
            Wire::str($wire['status'] ?? null),
            Wire::nullableStr($wire['consentRef'] ?? null),
            Wire::nullableStr($wire['assurance'] ?? null),
            Wire::str($wire['checkedAt'] ?? null),
            Wire::nullableStr($wire['basis'] ?? null),
            Wire::nullableStr($wire['tier'] ?? null),
            Wire::nullableStr($wire['validUntil'] ?? null),
            Wire::nullableStr($wire['revokedAt'] ?? null),
        );
    }

    /** True when the decision is an allow (the only true). */
    public function isAllow(): bool
    {
        return $this->decision === 'allow';
    }

    /**
     * True when this allow rests on a DECLARED NON-CONSENT basis (status
     * "necessity") rather than on a signed consent record. Never present such an
     * allow as "consented".
     */
    public function isNecessity(): bool
    {
        return $this->status === CheckStatus::NECESSITY;
    }
}
