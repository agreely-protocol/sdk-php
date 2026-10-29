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
     * @param array{cells:int, active:int, withdrawn:int, pending:int, unreadable:int, anchorBatchRefs?:int}|null $consentAnchorCounts
     *        per-cell tally of the consent anchor read, set when that read ran and did not fail; anchorBatchRefs is the
     *        refCount of the one AnchorBatch log of the trusted root at the block its refs sit in, when read
     * @param string|null $disclosureGateway the gateway that delivered the IPFS document, as configured (or the origin
     *        of a function's URL)
     * @param string|null $companyKeyRegistry with an rpcUrl, when a company key verified the signature: is that key in
     *        force on the company's domain at the instant it signed (companyKeyOf)? 'pass' (authentic), 'fail' (not
     *        authorised then, signed after the consent anchor, or voided), 'unavailable' (no window yet, recorded late,
     *        retired since on the issuer's own date, unreadable or unreachable, or no domain), 'skipped' (no key verified
     *        the signature). Null without an rpcUrl.
     * @param array<string,mixed>|null $companyKey what the registry said about the company key: verdict, domain,
     *        domainHash, keyId (sha256 of the raw Ed25519 key), signedAt and boundedBy (unix seconds or null),
     *        notYetEffective, and when read window {validFrom, retiredAt}, currentKeyId and rotated. On-chain times are
     *        decimal strings.
     * @param string|null $citizenKeyChain with an rpcUrl, on a passkey bundle: the person's key on chain (keyOf), read
     *        with the consent's anchor. 'pass' (the chain confirms the key at grantedAt), 'fail' (not in force at
     *        grantedAt, or granted after the consent's anchor), 'unavailable' (a caution or nothing to add: see
     *        citizenKey reason), 'skipped' (no salt and no keyOf registry read, or no verified assertion). Null
     *        without an rpcUrl and on other receipts.
     * @param array<string,mixed>|null $citizenKey the person's key reading: chainState, colour, reason, keyOfRegistry,
     *        withholds (true: overall cannot read verified), fingerprint and record when read, grantedAt and now (unix
     *        seconds)
     * @param array{binding:string, merkleRoot:string, consentRefs:list<string>}|null $signedAnchor a recorded receipt of
     *        version 4 in a bundle, whose signature held: the anchor batch it signs, and how the bundle's own unsigned
     *        root and refs stand against it ('bound', 'contradicts' or 'malformed')
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
        public readonly ?string $disclosureGateway = null,
        public readonly ?string $companyKeyRegistry = null,
        public readonly ?array $companyKey = null,
        public readonly ?string $citizenKeyChain = null,
        public readonly ?array $citizenKey = null,
        public readonly ?array $signedAnchor = null,
    ) {
    }

    /**
     * The array form, in the SAME field shape as the TS ReceiptVerification, so
     * BOTH SDKs canonicalize to byte-identical JCS (the golden-vector discipline).
     *
     * Every optional field appears only when set, as in TS.
     *
     * @return array{
     *     input:string,
     *     receiptType:string,
     *     companySignature:string,
     *     receiptIntegrity:string,
     *     citizenAssertion:string,
     *     disclosureCopy:string,
     *     documentAnchor:string,
     *     consentAnchor:string,
     *     cellLabelBinding:string,
     *     overall:string,
     *     withdrawal:string,
     *     withdrawnAt?:int,
     *     documentAnchorRecord?:array{anchoredAt:int, anchoredBlock:int},
     *     consentAnchorRecords?:list<array{consentRef:string, state:string, anchoredAt:int, revokedAt:int, anchoredBlock:int}>,
     *     consentAnchorCounts?:array{cells:int, active:int, withdrawn:int, pending:int, unreadable:int, anchorBatchRefs?:int},
     *     registryRefused?:true,
     *     disclosureGateway?:string,
     *     companyKeyRegistry?:string,
     *     companyKey?:array<string,mixed>,
     *     citizenKeyChain?:string,
     *     citizenKey?:array<string,mixed>,
     *     signedAnchor?:array{binding:string, merkleRoot:string, consentRefs:list<string>},
     *     notes:list<string>
     * }
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
        if ($this->disclosureGateway !== null) {
            $out['disclosureGateway'] = $this->disclosureGateway;
        }
        if ($this->companyKeyRegistry !== null) {
            $out['companyKeyRegistry'] = $this->companyKeyRegistry;
        }
        if ($this->companyKey !== null) {
            $out['companyKey'] = $this->companyKey;
        }
        if ($this->citizenKeyChain !== null) {
            $out['citizenKeyChain'] = $this->citizenKeyChain;
        }
        if ($this->citizenKey !== null) {
            $out['citizenKey'] = $this->citizenKey;
        }
        if ($this->signedAnchor !== null) {
            $out['signedAnchor'] = $this->signedAnchor;
        }
        $out['notes'] = $this->notes;
        return $out;
    }
}
