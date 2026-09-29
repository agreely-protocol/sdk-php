<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The 201 body from POST /v1/verbal-consents/{consentId}/paper: what the signed
 * paper confirmed and what it withdrew.
 *
 * The verbal consent is NEVER rewritten. Each purpose ticked on the paper moves into
 * a NEW manual consent (`manualConsentId`, tier "manual") and the gate re-points at
 * it: `confirmed` pairs each verbal cell with its new manual cell. Each purpose
 * unticked on the paper is recorded as a withdrawal dated at the signature
 * (`withdrawn`). When every box came back unticked, `manualConsentId`, `merkleRoot`
 * and `tier` are null.
 */
final class VerbalPaperResult
{
    /**
     * @param list<array{category:string,purpose:string,verbalConsentRef:string,consentRef:string}> $confirmed
     * @param list<array{category:string,purpose:string,consentRef:string}> $withdrawn
     */
    public function __construct(
        public readonly string $verbalConsentId,
        public readonly ?string $manualConsentId,
        public readonly ?string $merkleRoot,
        public readonly ?string $tier,
        public readonly array $confirmed,
        public readonly array $withdrawn,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $confirmed = [];
        foreach (Wire::objects($wire, 'confirmed') as $row) {
            $confirmed[] = [
                'category' => Wire::str($row['category'] ?? null),
                'purpose' => Wire::str($row['purpose'] ?? null),
                'verbalConsentRef' => Wire::str($row['verbalConsentRef'] ?? null),
                'consentRef' => Wire::str($row['consentRef'] ?? null),
            ];
        }
        $withdrawn = [];
        foreach (Wire::objects($wire, 'withdrawn') as $row) {
            $withdrawn[] = [
                'category' => Wire::str($row['category'] ?? null),
                'purpose' => Wire::str($row['purpose'] ?? null),
                'consentRef' => Wire::str($row['consentRef'] ?? null),
            ];
        }
        return new self(
            Wire::str($wire['verbalConsentId'] ?? null),
            Wire::nullableStr($wire['manualConsentId'] ?? null),
            Wire::nullableStr($wire['merkleRoot'] ?? null),
            Wire::nullableStr($wire['tier'] ?? null),
            $confirmed,
            $withdrawn,
        );
    }
}
