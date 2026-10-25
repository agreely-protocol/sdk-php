<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

/**
 * The full, honest result of an offline receipt verification, the PHP twin of
 * the TS SDK's ReceiptVerification. HONESTY IS THE POINT: each field reports what
 * was PROVED vs merely trusted, and `overall` never overstates it.
 *
 * input: 'bundle' | 'receipt'. Statuses are strings: 'pass' | 'fail' | 'unavailable'
 * | 'unbound' | 'skipped' | 'unsupported' ('unavailable' = the check could not
 * complete, distinct from 'fail', a real mismatch; 'unbound' = a passkey assertion
 * that verifies but that nothing ties to the receipt's content). overall: 'verified'
 * | 'partial' | 'incomplete' (a bare passkey receipt: verify its bundle) | 'failed' |
 * 'unavailable' | 'malformed' (a file the verifier cannot read: another bundle version,
 * a non-object receipt or offer, a number, nesting past 64 levels; no check ran, and it
 * says nothing about alteration). receiptType: 'company_attested' | 'company_documented'
 * (a verbal, telephone receipt: at most 'partial') | 'citizen'.
 */
final class ReceiptVerification
{
    /** @param list<string> $notes */
    public function __construct(
        public readonly string $input,
        public readonly string $receiptType,
        public readonly string $companySignature,
        public readonly string $receiptIntegrity,
        public readonly string $citizenAssertion,
        public readonly string $disclosureCopy,
        public readonly string $documentAnchor,
        public readonly string $consentAnchor,
        public readonly string $cellLabelBinding,
        public readonly string $overall,
        public readonly array $notes,
    ) {
    }

    /**
     * The array form, in the SAME field shape as the TS ReceiptVerification, so
     * BOTH SDKs canonicalize to byte-identical JCS (the golden-vector discipline).
     *
     * @return array{input:string,receiptType:string,companySignature:string,receiptIntegrity:string,citizenAssertion:string,disclosureCopy:string,documentAnchor:string,consentAnchor:string,cellLabelBinding:string,overall:string,notes:list<string>}
     */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'receiptType' => $this->receiptType,
            'companySignature' => $this->companySignature,
            'receiptIntegrity' => $this->receiptIntegrity,
            'citizenAssertion' => $this->citizenAssertion,
            'disclosureCopy' => $this->disclosureCopy,
            'documentAnchor' => $this->documentAnchor,
            'consentAnchor' => $this->consentAnchor,
            'cellLabelBinding' => $this->cellLabelBinding,
            'overall' => $this->overall,
            'notes' => $this->notes,
        ];
    }
}
