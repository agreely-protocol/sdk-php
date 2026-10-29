<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

/**
 * Recorded receipts (paper, telephone) as the web verifier classifies them (public/assets/verify/verify-core.js
 * receiptModeOf, recordedVersionOf, signedAnchorOf; ui.js recordedRootBinding). Versions 1 to 3 never carry an anchor;
 * a version 4 signs its anchor batch (credentialSubject.consent.merkleRoot, consentRefs). Vector: vectors/recorded-v4.json.
 */
final class Recorded
{
    private const SPEC = [
        'company_attested' => [
            'typeToken' => 'CompanyAttestedConsentReceipt',
            'evidenceType' => 'HandSignedPaperConsent',
            'consentKeys' => [
                'action', 'items', 'documentVersion', 'document', 'effectiveDate', 'grantedAt',
                'recordedAt', 'issuerDeclaration', 'declinedItems', 'confirms', 'merkleRoot', 'consentRefs',
            ],
            'evidenceKeys' => ['type', 'pdfHash', 'pdfHeldBy', 'documentVersion', 'documentVersionMatch'],
        ],
        'company_documented' => [
            'typeToken' => 'VerbalConsentReceipt',
            'evidenceType' => 'VerbalConsentDocumentedByOrganisation',
            'consentKeys' => [
                'action', 'channel', 'items', 'documentVersion', 'document', 'grantedAt',
                'recordedAt', 'issuerDeclaration', 'declinedItems', 'merkleRoot', 'consentRefs',
            ],
            'evidenceKeys' => ['type', 'scriptVersion', 'documentVersion'],
        ],
    ];

    private const TOP_LEVEL_KEYS = [
        'receiptVersion', '@context', 'type', 'id', 'issuer', 'assuranceLevel', 'validFrom',
        'validUntil', 'credentialSubject', 'evidence', 'proof',
    ];

    private const BYTES32_HEX = '/^0x[0-9a-f]{64}$/D';

    /**
     * Is this recorded receipt the shape its issuer writes, for a version this verifier reads?
     *   ok              the members of its type and version, and nothing else;
     *   mismatch        a member its type or version never carries, or one it must carry missing (a version 3 with an
     *                   anchor included): `reason` says which;
     *   unknown-version a receiptVersion this verifier does not read.
     * The proof array is judged on its own (recordedProofFailure) and is not part of this answer.
     *
     * @param array<array-key,mixed> $r
     * @param 'company_attested'|'company_documented' $type
     * @return array{kind:'ok'}|array{kind:'mismatch', reason:string}|array{kind:'unknown-version', version:string}
     */
    public static function shapeOf(array $r, string $type): array
    {
        $spec = self::SPEC[$type];
        $other = self::SPEC[$type === 'company_attested' ? 'company_documented' : 'company_attested'];
        $types = Json::arr($r['type'] ?? null);
        if (
            !in_array('ConsentReceipt', $types, true) || !in_array($spec['typeToken'], $types, true)
            || ($r['assuranceLevel'] ?? null) !== $type
        ) {
            return ['kind' => 'mismatch', 'reason' => "its type and assuranceLevel do not both name a {$type} receipt"];
        }
        if (in_array($other['typeToken'], $types, true)) {
            return ['kind' => 'mismatch', 'reason' => 'its type names both a paper and a telephone receipt'];
        }
        if (!Json::onlyKeys($r, self::TOP_LEVEL_KEYS)) {
            return ['kind' => 'mismatch', 'reason' => 'it carries a top-level member the issuer never writes'];
        }
        $subject = $r['credentialSubject'] ?? null;
        if (!Json::isObject($subject) || !Json::onlyKeys($subject, ['consent'])) {
            return ['kind' => 'mismatch', 'reason' => 'its credentialSubject carries a member other than consent'];
        }
        $consentValue = Json::obj($subject)['consent'] ?? null;
        if (!Json::isObject($consentValue) || !Json::onlyKeys($consentValue, $spec['consentKeys'])) {
            return ['kind' => 'mismatch', 'reason' => 'its consent carries a member the issuer never writes'];
        }
        $consent = Json::obj($consentValue);
        if (($consent['action'] ?? null) !== 'grant') {
            return ['kind' => 'mismatch', 'reason' => 'its consent action is not grant'];
        }
        if ($type === 'company_documented' && ($consent['channel'] ?? null) !== 'telephone') {
            return ['kind' => 'mismatch', 'reason' => 'its consent channel is not telephone'];
        }
        $evidence = $r['evidence'] ?? null;
        if (
            !Json::isObject($evidence) || !Json::onlyKeys($evidence, $spec['evidenceKeys'])
            || (Json::obj($evidence)['type'] ?? null) !== $spec['evidenceType']
        ) {
            return ['kind' => 'mismatch', 'reason' => 'its evidence is not the evidence of its type'];
        }
        $hasRecordedAt = array_key_exists('recordedAt', $consent);
        $hasDeclaration = array_key_exists('issuerDeclaration', $consent);
        $hasAnchor = array_key_exists('merkleRoot', $consent) || array_key_exists('consentRefs', $consent);
        $recordedAtOk = is_string($consent['recordedAt'] ?? null);
        if (!array_key_exists('receiptVersion', $r)) {
            return !$hasRecordedAt && !$hasDeclaration && !$hasAnchor
                ? ['kind' => 'ok']
                : ['kind' => 'mismatch', 'reason' => 'a receipt without a receiptVersion carries a member only a later version writes'];
        }
        $v = $r['receiptVersion'];
        if ($v === '2') {
            return $recordedAtOk && !$hasDeclaration && !$hasAnchor
                ? ['kind' => 'ok']
                : ['kind' => 'mismatch', 'reason' => 'a version 2 receipt carries a member it never writes, or lacks its recordedAt'];
        }
        if ($v === '3' || $v === '4') {
            if (!$recordedAtOk) {
                return ['kind' => 'mismatch', 'reason' => "a version {$v} receipt lacks its recordedAt"];
            }
            if ($v === '3' && $hasAnchor) {
                return ['kind' => 'mismatch', 'reason' => 'a version 3 receipt carries an anchor, which only a version 4 signs'];
            }
            return !$hasDeclaration || Json::isObject($consent['issuerDeclaration'])
                ? ['kind' => 'ok']
                : ['kind' => 'mismatch', 'reason' => 'its issuerDeclaration is not an object'];
        }
        return ['kind' => 'unknown-version', 'version' => is_string($v) ? $v : self::jsonText($v)];
    }

    /**
     * The anchor batch a version 4 receipt signs. Null for any other version; wellFormed false when a member is missing
     * or malformed (the root and every ref 0x + 64 lowercase hex, at least one ref, no duplicate).
     *
     * @param array<array-key,mixed> $r
     * @return array{wellFormed:bool, merkleRoot:string, consentRefs:list<string>}|null
     */
    public static function signedAnchorOf(array $r): ?array
    {
        $subject = $r['credentialSubject'] ?? null;
        $consent = Json::isObject($subject) ? Json::obj($subject)['consent'] ?? null : null;
        if (($r['receiptVersion'] ?? null) !== '4' || !Json::isObject($consent)) {
            return null;
        }
        $consent = Json::obj($consent);
        $root = $consent['merkleRoot'] ?? null;
        $refs = $consent['consentRefs'] ?? null;
        $malformed = ['wellFormed' => false, 'merkleRoot' => '', 'consentRefs' => []];
        if (!is_string($root) || preg_match(self::BYTES32_HEX, $root) !== 1) {
            return $malformed;
        }
        if (!is_array($refs) || !array_is_list($refs) || $refs === []) {
            return $malformed;
        }
        $list = [];
        foreach ($refs as $ref) {
            if (!is_string($ref) || preg_match(self::BYTES32_HEX, $ref) !== 1 || in_array($ref, $list, true)) {
                return $malformed;
            }
            $list[] = $ref;
        }
        return ['wellFormed' => true, 'merkleRoot' => $root, 'consentRefs' => $list];
    }

    /**
     * What a recorded receipt's own signature says about the anchor its bundle ships, once that signature held:
     *   bound        the envelope root equals the signed root and every envelope ref is a signed ref;
     *   contradicts  the envelope root differs, or the envelope carries a ref the signature does not;
     *   malformed    a version 4 missing or malforming a member, or a bundle with no envelope root: no anchor is tied;
     *   preV4        signed before version 4: no anchor is tied.
     * Fewer envelope refs than signed ones is no contradiction: the chain read takes the signed list.
     *
     * @param array<array-key,mixed> $r
     * @param array<array-key,mixed> $bundle
     * @param list<string> $envelopeRefs
     * @return array{binding:'bound'|'contradicts'|'malformed'|'preV4', merkleRoot:string, consentRefs:list<string>}
     */
    public static function rootBinding(array $r, array $bundle, array $envelopeRefs): array
    {
        $signed = self::signedAnchorOf($r);
        if ($signed === null) {
            return ['binding' => 'preV4', 'merkleRoot' => '', 'consentRefs' => []];
        }
        if (!$signed['wellFormed']) {
            return ['binding' => 'malformed', 'merkleRoot' => '', 'consentRefs' => []];
        }
        $rogueRef = false;
        foreach ($envelopeRefs as $ref) {
            if (!in_array($ref, $signed['consentRefs'], true)) {
                $rogueRef = true;
            }
        }
        // A null or empty root is no root: nothing is tied. Only a value that differs contradicts.
        $present = array_key_exists('merkleRoot', $bundle) && $bundle['merkleRoot'] !== null && $bundle['merkleRoot'] !== '';
        $shipped = $present ? $bundle['merkleRoot'] : null;
        $rootDiffers = $present
            && (!is_string($shipped) || '0x' . strtolower((string) preg_replace('/^0x/i', '', $shipped)) !== $signed['merkleRoot']);
        if ($rogueRef || $rootDiffers) {
            return ['binding' => 'contradicts', 'merkleRoot' => $signed['merkleRoot'], 'consentRefs' => $signed['consentRefs']];
        }
        if (!$present) {
            return ['binding' => 'malformed', 'merkleRoot' => '', 'consentRefs' => []];
        }
        return ['binding' => 'bound', 'merkleRoot' => $signed['merkleRoot'], 'consentRefs' => $signed['consentRefs']];
    }

    /** JSON.stringify of a non-string receiptVersion, as the TS SDK names it in its refusal. */
    private static function jsonText(mixed $v): string
    {
        $text = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($text) ? $text : 'undefined';
    }
}
