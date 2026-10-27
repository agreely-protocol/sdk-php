<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

/**
 * The full, honest result of an offline receipt verification, the PHP twin of
 * the TS SDK's ReceiptVerification. HONESTY IS THE POINT: each field reports what
 * was PROVED vs merely trusted, and `overall` never overstates it.
 *
 * input: 'bundle' | 'receipt'. Statuses are strings: 'pass' | 'fail' | 'unavailable'
 * | 'unbound' | 'withdrawn' | 'skipped' | 'unsupported' ('unavailable' = the check could not
 * complete, distinct from 'fail', a real mismatch; 'unbound' = a passkey assertion
 * that verifies but that nothing ties to the receipt's content; 'withdrawn' = an authentic
 * consent under the receipt's root whose withdrawal the chain records: neither a pass nor
 * a forgery). overall: 'verified' | 'partial' | 'incomplete' (a bare passkey receipt:
 * verify its bundle) | 'failed' | 'unavailable' | 'malformed' (a file the verifier cannot
 * read: another bundle version, a non-object receipt or offer, a passkey receipt in a bundle
 * carrying a field the issuer never writes, a number, nesting past 64 levels; no check ran,
 * and it says nothing about alteration) | 'withdrawn' (every other
 * decisive check verified, and the consent was withdrawn on chain). receiptType: 'company_attested' | 'company_documented'
 * (a verbal, telephone receipt: at most 'partial') | 'citizen'.
 *
 * withdrawal says whether the consent is still in force, as the chain records it, and is always present:
 * 'active' (every accepted cell read on chain, rpcUrl, under the bundle's bound Merkle root, none withdrawn) |
 * 'withdrawn' (the chain records a cell's withdrawal under that root; withdrawnAt gives the latest, unix seconds,
 * when the record carries it) | 'not_checked' (nothing established it: no rpcUrl, a paper, telephone or bare
 * passkey receipt, a root no verified passkey assertion binds, or a read that did not cover every cell). It says
 * nothing about authenticity: read overall first. Without an rpcUrl a withdrawn consent still reads 'verified'.
 */
final class ReceiptVerification
{
    /**
     * @param list<string> $notes
     * @param array{anchoredAt:int, anchoredBlock:int}|null $documentAnchorRecord present when documentAnchor
     *        passed: when, and in which block, the document CID was anchored (unix seconds)
     * @param list<array{consentRef:string, state:string, anchoredAt:int, revokedAt:int, anchoredBlock:int}>|null $consentAnchorRecords
     *        present when consentAnchor read a cell under the bundle's Merkle root: one record per such cell,
     *        state 'active' | 'withdrawn', revokedAt 0 while in force
     * @param array{cells:int, active:int, withdrawn:int, pending:int, unreadable:int}|null $consentAnchorCounts per-cell
     *        tally of the consent anchor read, set when that read ran and did not fail
     */
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
        public readonly string $withdrawal,
        public readonly array $notes,
        public readonly ?int $withdrawnAt = null,
        public readonly ?array $documentAnchorRecord = null,
        public readonly ?array $consentAnchorRecords = null,
        public readonly ?array $consentAnchorCounts = null,
        public readonly bool $registryRefused = false,
    ) {
    }

    /**
     * The array form, in the SAME field shape as the TS ReceiptVerification, so
     * BOTH SDKs canonicalize to byte-identical JCS (the golden-vector discipline).
     *
     * withdrawnAt, the anchor records, the tally and registryRefused appear only when set, as in TS.
     *
     * @return array{input:string,receiptType:string,companySignature:string,receiptIntegrity:string,citizenAssertion:string,disclosureCopy:string,documentAnchor:string,consentAnchor:string,cellLabelBinding:string,overall:string,withdrawal:string,withdrawnAt?:int,documentAnchorRecord?:array{anchoredAt:int, anchoredBlock:int},consentAnchorRecords?:list<array{consentRef:string, state:string, anchoredAt:int, revokedAt:int, anchoredBlock:int}>,consentAnchorCounts?:array{cells:int, active:int, withdrawn:int, pending:int, unreadable:int},registryRefused?:true,notes:list<string>}
     */
    public function toArray(): array
    {
        $out = [
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
            'withdrawal' => $this->withdrawal,
        ];
        if ($this->withdrawnAt !== null) {
            $out['withdrawnAt'] = $this->withdrawnAt;
        }
        if ($this->documentAnchorRecord !== null) {
            $out['documentAnchorRecord'] = $this->documentAnchorRecord;
        }
        if ($this->consentAnchorRecords !== null) {
            $out['consentAnchorRecords'] = $this->consentAnchorRecords;
        }
        if ($this->consentAnchorCounts !== null) {
            $out['consentAnchorCounts'] = $this->consentAnchorCounts;
        }
        if ($this->registryRefused) {
            $out['registryRefused'] = true;
        }
        $out['notes'] = $this->notes;
        return $out;
    }
}
