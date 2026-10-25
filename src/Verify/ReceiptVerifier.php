<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

use Agreely\Sdk\Crypto\Canonicalizer;
use Agreely\Sdk\Crypto\Cose;
use Agreely\Sdk\Crypto\Keccak;
use Agreely\Sdk\Crypto\Multibase;
use Agreely\Sdk\Crypto\Signature;
use stdClass;
use Throwable;

/**
 * The offline-first, honest consent-receipt verifier, a byte-for-byte behavioural
 * port of the TS SDK's ReceiptVerifier. Its ReceiptVerification result canonicalizes
 * (JCS) identically to the TS output, asserted by the shared golden vectors.
 *
 * "Offline-first", not "fully offline": the signature/assertion checks need the
 * signing key from the issuer/citizen DID document (by default one HTTPS resolution,
 * or supply the DID document(s) via an injected `resolver` for an air-gapped verify).
 * The IPFS disclosure copy and the on-chain anchors are the OPT-IN extra calls. When
 * a DID cannot be resolved the affected check is reported "unavailable"
 * (inconclusive), never "fail" (a real tamper).
 *
 * The input is a bare receipt OR the verification bundle Agreely issues with it
 * ({ version, receipt, signedOffer, merkleRoot, consentRefs, didDocuments }), as its JSON text, as what
 * json_decode() returns, or as an array.
 *
 * TRAP: json_decode($text, true) turns a JSON object keyed "0", "1"... into a list and {} into [], so it
 * cannot tell an object from an array. Every JSON this verifier decodes itself (the input text, DID documents,
 * IPFS and RPC bodies) is decoded with objects kept; an array it is handed reads its lists as lists.
 *
 * A recorded receipt (paper or telephone) is fully offline-sound: Ed25519 over the
 * JCS body. That proves the COMPANY attested to what the body says, not that a human
 * signed. A telephone receipt is at most "partial": it is the organisation's word.
 *
 * A passkey receipt is verifiable ONLY from its bundle, mirroring the app's /verify
 * page (verify-core.js): proof[0] is the company signature over JCS(signedOffer), the
 * receipt must match that offer field by field, and the passkey challenge is
 * recomputed as keccak256(JCS({issuer, subject, requestId, action, grantedAt,
 * merkleRoot}) || nonce). A BARE passkey receipt omits the offer, the nonce and the
 * Merkle root, so its assertion is reported "unbound" and the overall "incomplete".
 *
 * TRAP: the keys always come from the resolver, never from the bundle's didDocuments.
 * Anyone can write a bundle that carries their own key under someone else's DID.
 *
 * The input is hostile: anything this verifier cannot read (a bundle of another version, a
 * non-object receipt or offer, a number, nesting past MAX_INPUT_DEPTH) reads "malformed", and
 * verify() returns on every input, a deep one included. The citizen half is checked only for a
 * did:agreely:citizen: subject, whatever the resolver.
 *
 * SECURITY: the default did:web resolver fetches whatever host the receipt names, over
 * HTTPS. A verified signature then proves the receipt was signed by a key that host
 * publishes, not that the host is the organisation you expect. For receipts you did
 * not fetch yourself, inject a `resolver` that pins the issuers you trust.
 *
 * Options (all network seams injectable, so tests run with NO network):
 *   - resolver:            callable(string $did): DID document as JSON text, json_decode() object or array, or null
 *   - companyDidHost:      string (default app.agreely.ca, the Agreely WEB tier, which is what
 *                          serves /c/{slug}/did.json) for resolveCompanyDid
 *   - citizenResolverBaseUrl: string (default https://my.agreely.ca, the Agreely CITIZEN tier,
 *                          which is what serves /did/{did})
 *   - verifyDisclosure:    bool, default false: OPT-IN fetch of the IPFS document copy
 *   - ipfsGateway:         callable(string $cid): string: CID -> URL
 *   - httpGet:             callable(string $url): ?string: fetch body (IPFS, DID), null on any failure
 *   - httpPost:            callable(string $url, string $body): ?string: JSON-RPC, null on any failure
 *   - rpcUrl, registryAddress, chainId: opt-in on-chain documentAnchor and consentAnchor
 *   - registryDeployBlock, logRangeLimit (10000), maxLogQueries (10): bound the log query
 *   - timeoutMs:           int, default 10000, per HTTP call
 *   - rpcDeadlineMs:       int, default 30000: the whole on-chain sequence of one verification,
 *                          from its first RPC call; past it the pending anchor checks read "unavailable"
 */
final class ReceiptVerifier
{
    /**
     * The host that SERVES company did:web documents, i.e. the Agreely WEB (dashboard)
     * tier: `did:web:app.agreely.ca:c:{slug}` resolves to
     * `https://app.agreely.ca/c/{slug}/did.json`.
     *
     * It is NOT the apex `agreely.ca`, which is the marketing site and 404s on that
     * path. `GET /c/{slug}/did.json` is routed on MODE=WEB only; the api and citizen
     * tiers do not serve it.
     */
    private const DEFAULT_COMPANY_HOST = 'app.agreely.ca';

    /**
     * The base URL of the CITIZEN tier, which serves `GET /did/{did}` for
     * `did:agreely:citizen:*`. It is NOT the api tier: `GET /did/{did}` is routed on
     * MODE=CITIZEN only, and api.agreely.ca 404s on it, which made every citizen
     * assertion resolve to "unavailable" by default.
     */
    private const DEFAULT_CITIZEN_BASE = 'https://my.agreely.ca';
    private const DEFAULT_IPFS_GATEWAY = 'https://gateway.lighthouse.storage/ipfs/';
    // The LIVE Base mainnet AgreelyRegistry, deployed at block 48889369 (the 2026-07-19
    // redeploy that carries the DID-tagged anchor events). EIP-55 checksummed.
    //
    // KEEP THIS IN SYNC WITH THE DEPLOYMENT. It is pinned BY HAND here (the SDK has no
    // config to read), and the app resolves its own address from config/anchor-network.json,
    // so a redeploy does NOT propagate here on its own. A stale address is SILENT and WORSE
    // than a missing one: the superseded contract still exists on chain and still answers,
    // it just holds no anchors, so every anchor check would contradict a valid receipt.
    // The predecessor 0x1E3121CFB5dfE1ac0b0265790D2bdA709725cF8B did exactly that in v0.2.0.
    // ReceiptVerifierRegistryTest pins this value so a silent edit fails a test.
    //
    // Setting it back to null reports the on-chain checks as "skipped" for mainnet (never a
    // false result), which is the correct fallback if it is ever unknown.
    private const MAINNET_REGISTRY_ADDRESS = '0x23577fafFa306375028D33a559D0F95Ced9424DB';
    private const MAINNET_DEPLOY_BLOCK = 48889369;
    // Base mainnet (chainId 8453) is the default. Base Sepolia (84532) remains available as an
    // explicit opt-in for integrators testing against the testnet registry (pass chainId 84532).
    private const DEFAULT_CHAIN_ID = 8453;
    private const REGISTRY_BY_CHAIN = [
        8453 => self::MAINNET_REGISTRY_ADDRESS,        // Base mainnet (default)
        84532 => '0x3532a9DfF3C910152543afCa49De7c66113C5312', // Base Sepolia (optional testing)
    ];
    private const DEPLOY_BLOCK_BY_CHAIN = [8453 => self::MAINNET_DEPLOY_BLOCK, 84532 => 43698609];
    // `registry(bytes32)` answers the deployed `struct Anchor` as four words whose ORDER changed
    // between deployments: Sepolia still runs the pre-repack layout.
    private const LEGACY_LAYOUT_CHAINS = [84532];
    private const DEFAULT_LOG_RANGE_LIMIT = 10000;
    private const DEFAULT_MAX_LOG_QUERIES = 10;
    private const DEFAULT_TIMEOUT_MS = 10000;
    private const DEFAULT_RPC_DEADLINE_MS = 30000;
    // A malformed bundle must not spray unbounded RPC calls.
    private const MAX_CONSENT_REFS = 32;
    // A genuine bundle nests about eight levels deep. Past this, the recursive canonicalizer can
    // exhaust the stack on a caller that decoded the JSON with a large depth.
    private const MAX_INPUT_DEPTH = 64;
    // Every Agreely citizen is did:agreely:citizen:* (app Citizen\Did), the suffix URL-safe because it is joined to
    // the resolver path as is (citizen-key.js isAgreelyCitizenDid). A passkey under any other subject is not checked:
    // a did:web subject would let anyone serve their own key from their host.
    private const CITIZEN_DID_PREFIX = 'did:agreely:citizen:';
    private const CITIZEN_DID_PATTERN = '/^did:agreely:citizen:(?!\.+$)[A-Za-z0-9._-]{1,128}$/D';
    // An instant as Agreely writes one: a calendar date (UTC midnight) or an RFC 3339 date-time with a zone. Anything
    // else is unreadable, which never confirms a key nor satisfies a window.
    private const INSTANT_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2}))?$/D';
    private const CITIZEN_KEY_REFUSAL = [
        'unlisted' => "the citizen's DID document does not list the key the assertion verified with under authentication (with the "
            . "same DID), so it is not proven to be the person's passkey.",
        'outsideWindow' => "the citizen's DID document lists the key the assertion verified with, but its validity window [validFrom, "
            . "validUntil) does not cover the receipt's grantedAt: the key did not belong to the person at that date.",
        'grantedAtUnreadable' => "the receipt's grantedAt is not a readable date, so no validity window of the person's key can cover it.",
    ];
    // The top level of a passkey receipt as ConsentReceipt::build writes it. The offer carries
    // only issuer and validUntil of these; type and @context are constants, the id is unsigned.
    private const PASSKEY_RECEIPT_KEYS = ['@context', 'type', 'id', 'issuer', 'validFrom', 'validUntil', 'credentialSubject', 'proof'];
    private const PASSKEY_RECEIPT_TYPE = ['VerifiableCredential', 'ConsentReceipt'];
    private const PASSKEY_RECEIPT_CONTEXT = ['https://www.w3.org/ns/credentials/v2', 'https://agreely.ca/credentials/consent/v1'];
    private const BUNDLE_VERSION = '1';
    private const MAX_SAFE_INTEGER = 9007199254740991;
    private const RPC_TRANSPORT_FAILURE = 'the RPC call failed (transport error, HTTP error or timeout)';

    private const ALLOWED_CONSENT_KEYS = [
        'action',
        'items',
        'requestId',
        'grantedAt',
        'document',
        'disclosureHash',
        'declinedItems',
        'disclosureVersion',
        'legalBasis',
        'minor',
    ];

    private Canonicalizer $canonicalizer;
    /** The deadline (hrtime, ns) of the current verification's RPC sequence, set by its first call. */
    private ?int $rpcDeadline = null;

    /** @param array<string,mixed> $opts */
    public function __construct(private readonly array $opts = [])
    {
        $this->canonicalizer = new Canonicalizer();
    }

    /**
     * Is `$value` a verification bundle? Version "1", a `receipt` object, and a `signedOffer` object
     * when it carries one (a recorded receipt's bundle has none). Any other top-level key, `type`
     * included, does not make it a bare receipt.
     */
    public static function isVerificationBundle(mixed $value): bool
    {
        return self::classifyInput($value) === 'bundle';
    }

    /** 'bundle' | 'receipt' | 'malformed-bundle' | 'malformed' */
    private static function classifyInput(mixed $value): string
    {
        if (!self::isPlainObject($value)) {
            return 'malformed';
        }
        $value = self::asObject($value);
        $bundleShaped = array_key_exists('receipt', $value) || array_key_exists('signedOffer', $value) || array_key_exists('version', $value);
        if (!$bundleShaped) {
            return 'receipt';
        }
        // A receipt VC carries its proof at the top level; a bundle never does.
        if (array_key_exists('proof', $value) && !array_key_exists('receipt', $value)) {
            return 'receipt';
        }
        $offerOk = !array_key_exists('signedOffer', $value) || self::isPlainObject($value['signedOffer']);
        return ($value['version'] ?? null) === self::BUNDLE_VERSION && self::isPlainObject($value['receipt'] ?? null) && $offerOk
            ? 'bundle'
            : 'malformed-bundle';
    }

    /**
     * @param mixed $input a receipt VC or verification bundle: its JSON text, what json_decode() returns, or an array.
     *                     Never throws on bad input.
     */
    public function verify(mixed $input): ReceiptVerification
    {
        $this->rpcDeadline = null;
        if (is_string($input)) {
            $decoded = self::decodeJson($input);
            if ($decoded === null) {
                return self::malformedResult('receipt', self::receiptType([]), 'the input is not JSON text');
            }
            $input = $decoded['value'];
        }
        $kind = self::classifyInput($input);
        try {
            if ($kind === 'malformed') {
                throw new MalformedInput('the input is not a JSON object');
            }
            if ($kind === 'malformed-bundle') {
                throw new MalformedInput(self::bundleShapeFailure(self::asObject($input)));
            }
            if (self::exceedsDepth($input, self::MAX_INPUT_DEPTH)) {
                throw new MalformedInput('the input nests deeper than ' . self::MAX_INPUT_DEPTH . ' levels');
            }
            return $this->verifyShaped(self::asObject(self::exactJson($input)), $kind === 'bundle');
        } catch (Throwable $e) {
            $reason = $e instanceof MalformedInput ? $e->getMessage() : 'it could not be processed (' . self::errorName($e) . ')';
            $inBundle = $kind === 'bundle' || $kind === 'malformed-bundle';
            $r = self::asObject($inBundle ? self::asObject($input)['receipt'] ?? null : $input);
            return self::malformedResult($inBundle ? 'bundle' : 'receipt', self::receiptType($r), $reason);
        }
    }

    /** @param array<array-key,mixed> $input */
    private function verifyShaped(array $input, bool $isBundle): ReceiptVerification
    {
        $bundle = $isBundle ? $input : null;
        $r = $bundle !== null ? self::asObject($bundle['receipt'] ?? null) : $input;
        $type = self::receiptType($r);
        $notes = [];
        if ($bundle !== null) {
            $notes[] = 'Read as a verification bundle. Keys are resolved from the issuer and citizen DIDs (or your resolver), '
                . "never taken from the bundle's own didDocuments, which anyone can write.";
        }

        if ($type !== 'citizen') {
            return $this->verifyRecorded($r, $type, $bundle !== null, $notes);
        }
        if ($bundle === null) {
            return $this->verifyBarePasskey($r, $notes);
        }
        $offer = $bundle['signedOffer'] ?? null;
        $merkleRoot = $bundle['merkleRoot'] ?? null;
        if (!is_array($offer) || $offer === [] || array_is_list($offer)) {
            throw new MalformedInput('the bundle carries a passkey receipt but no signedOffer object');
        }
        if (!is_string($merkleRoot) || $merkleRoot === '') {
            throw new MalformedInput('the bundle carries a passkey receipt but no merkleRoot string');
        }
        return $this->verifyPasskeyBundle($r, $bundle, $offer, $merkleRoot, $notes);
    }

    /**
     * A paper (company_attested) or telephone (company_documented) receipt: the company signed the body itself.
     *
     * @param array<array-key,mixed> $r
     * @param list<string> $notes
     */
    private function verifyRecorded(array $r, string $type, bool $fromBundle, array $notes): ReceiptVerification
    {
        $companySignature = $this->verifyCompanySignature($r, $notes, $type);
        $notes[] = $type === 'company_attested'
            ? 'A company-attested receipt carries no citizen passkey assertion, so citizenAssertion is not applicable.'
            : 'A verbal (company-documented) receipt carries no citizen passkey assertion, so citizenAssertion is not applicable.';
        // The signature is taken over the receipt body itself, so it IS the integrity check.
        $receiptIntegrity = $companySignature;
        $cellLabelBinding = self::assessRecordedCellLabels($companySignature, $notes);
        $disclosureCopy = $this->verifyRecordedDisclosureCopy($r, $notes);
        $documentAnchor = $this->verifyDocumentAnchor($r, $notes);
        $notes[] = 'consentAnchor skipped: a recorded receipt carries no consentRef to read on chain.';

        if ($companySignature === 'fail' || $disclosureCopy === 'fail' || $documentAnchor === 'fail') {
            $overall = 'failed';
        } elseif ($companySignature === 'unavailable') {
            $overall = 'unavailable';
        } else {
            // A telephone receipt is the organisation's word, authenticated: never more than partial.
            $overall = $type === 'company_attested' ? 'verified' : 'partial';
        }

        return new ReceiptVerification(
            input: $fromBundle ? 'bundle' : 'receipt',
            receiptType: $type,
            companySignature: $companySignature,
            receiptIntegrity: $receiptIntegrity,
            citizenAssertion: 'unsupported',
            disclosureCopy: $disclosureCopy,
            documentAnchor: $documentAnchor,
            consentAnchor: 'skipped',
            cellLabelBinding: $cellLabelBinding,
            overall: $overall,
            notes: $notes,
        );
    }

    /**
     * A passkey receipt WITHOUT its signed offer and Merkle root: nothing binds the assertion to the content.
     *
     * @param array<array-key,mixed> $r
     * @param list<string> $notes
     */
    private function verifyBarePasskey(array $r, array $notes): ReceiptVerification
    {
        $notes[] = 'Company signature UNSUPPORTED on a bare passkey receipt: the company signed the offer, which travels only in '
            . 'the verification bundle.';
        $notes[] = 'Receipt integrity UNSUPPORTED on a bare passkey receipt: nothing on the receipt alone binds its purposes, cells, '
            . 'dates, document or issuer to a signature.';
        $passkey = $this->verifyPasskeySignature($r, $notes);
        $citizenAssertion = $passkey['status'];
        if ($passkey['status'] === 'pass') {
            $citizenAssertion = 'unbound';
            $notes[] = "Citizen assertion UNBOUND: the passkey signature verifies against {$passkey['vm']}, but its challenge is computed "
                . 'from a requestId, a Merkle root and a nonce the bare receipt does not carry, so nothing ties it to this '
                . "receipt's content. A receipt rewritten around a genuine assertion reads the same.";
        }
        $notes[] = 'Cell labels UNSUPPORTED on a bare passkey receipt: the category/purpose labels are covered only by the company '
            . 'signature over the offer, which travels in the verification bundle. Do NOT trust the displayed labels.';
        $notes[] = 'disclosureCopy UNSUPPORTED on a bare passkey receipt: its disclosureHash (and a v2 legalBasis) is signed by '
            . 'nobody, so no copy can be checked against it.';
        $documentAnchor = $this->verifyDocumentAnchor($r, $notes);
        $notes[] = 'consentAnchor skipped: without the bundle there is no consentRef and no bound Merkle root to read on chain.';

        $overall = $citizenAssertion === 'fail' || $documentAnchor === 'fail' ? 'failed' : 'incomplete';
        $notes[] = 'INCOMPLETE: a passkey receipt cannot be verified on its own. Verify the verification bundle issued with it '
            . '(the file that carries receipt, signedOffer and merkleRoot).';

        return new ReceiptVerification(
            input: 'receipt',
            receiptType: 'citizen',
            companySignature: 'unsupported',
            receiptIntegrity: 'unsupported',
            citizenAssertion: $citizenAssertion,
            disclosureCopy: 'unsupported',
            documentAnchor: $documentAnchor,
            consentAnchor: 'skipped',
            cellLabelBinding: 'unsupported',
            overall: $overall,
            notes: $notes,
        );
    }

    /**
     * A passkey receipt with its bundle: the same checks as the app's /verify page.
     *
     * @param array<array-key,mixed> $r
     * @param array<array-key,mixed> $bundle
     * @param array<array-key,mixed> $offer
     * @param list<string> $notes
     */
    private function verifyPasskeyBundle(array $r, array $bundle, array $offer, string $merkleRoot, array $notes): ReceiptVerification
    {
        $companySignature = $this->verifyOfferSignature($r, $offer, $notes);
        $fieldFailure = $this->integrityFailure($r, $offer);
        $fieldIntegrity = $fieldFailure === null ? 'pass' : 'fail';
        // The passkey signer chooses grantedAt, so only the company-signed offer's own window bounds it.
        $window = $fieldIntegrity === 'pass' && $companySignature === 'pass' ? self::offerWindowOf($r, $offer) : null;
        $receiptIntegrity = self::checkReceiptIntegrity($fieldFailure, $window, $notes);
        $outsideWindow = $window !== null && $window['failure'] !== null;

        $passkey = $this->verifyPasskeySignature($r, $notes);
        $citizenAssertion = $passkey['status'];
        if ($passkey['status'] === 'pass') {
            $bound = $this->citizenChallengeBinds($r, $offer, $merkleRoot, $passkey['challenge']);
            // Only a bound assertion makes grantedAt the person's own date, so only then is the key judged against it,
            // and only at a date the offer allows: a key confirmed at a date in doubt proves nothing.
            if ($bound === 'ok' && $outsideWindow) {
                $citizenAssertion = 'unavailable';
                $notes[] = "Citizen assertion NOT EVALUATED: the acceptance date (grantedAt) cannot be placed inside the company-signed "
                    . "offer's window, so the person's key is not evaluated at that date.";
            } elseif ($bound === 'ok' && $passkey['standing'] !== 'confirmed') {
                $citizenAssertion = 'fail';
                $notes[] = 'Citizen assertion FAILED: ' . self::CITIZEN_KEY_REFUSAL[$passkey['standing'] === '' ? 'unlisted' : $passkey['standing']];
            } elseif ($bound === 'ok') {
                $notes[] = "Citizen assertion verified and bound: the passkey {$passkey['vm']} signed the challenge recomputed from this "
                    . "receipt's issuer, subject, requestId, action and grantedAt, the bundle's Merkle root and the offer's nonce. "
                    . "The citizen's DID document lists that key under authentication, valid at grantedAt.";
            } else {
                $citizenAssertion = 'fail';
                $notes[] = $bound === 'mismatch'
                    ? 'Citizen assertion FAILED: the passkey signature verifies, but the challenge it signed does not recompute '
                        . "from this receipt's issuer, subject, requestId, action and grantedAt with the bundle's Merkle root and "
                        . 'nonce. The assertion belongs to another consent.'
                    : 'Citizen assertion FAILED: the challenge cannot be recomputed (a context field, the Merkle root or the '
                        . "offer's nonce is missing or malformed).";
            }
        }

        if ($companySignature === 'pass' && $fieldIntegrity === 'pass') {
            $cellLabelBinding = 'pass';
            $notes[] = 'Cell labels bound: every accepted and declined cell is an exact item of the company-signed offer.';
        } elseif ($companySignature === 'fail' || $fieldIntegrity === 'fail') {
            $cellLabelBinding = 'fail';
            $notes[] = "Cell labels UNBOUND: the receipt's cells are not proven to be items of a verified company offer.";
        } else {
            $cellLabelBinding = 'unavailable';
            $notes[] = 'Cell-label binding UNVERIFIABLE: the issuer DID could not be resolved, so the offer that binds the labels '
                . 'could not be checked.';
        }

        $disclosureCopy = $this->verifyOfferDisclosureCopy($offer, $companySignature, $notes);
        $documentAnchor = $this->verifyDocumentAnchor($r, $notes);
        $consentAnchor = $this->verifyConsentAnchor($bundle, $merkleRoot, $citizenAssertion, $notes);

        $checks = [$companySignature, $receiptIntegrity, $citizenAssertion, $disclosureCopy, $documentAnchor, $consentAnchor];
        if (in_array('fail', $checks, true)) {
            $overall = 'failed';
        } elseif ($companySignature === 'pass' && $receiptIntegrity === 'pass' && $citizenAssertion === 'pass') {
            $overall = 'verified';
        } else {
            $overall = 'unavailable';
        }

        return new ReceiptVerification(
            input: 'bundle',
            receiptType: 'citizen',
            companySignature: $companySignature,
            receiptIntegrity: $receiptIntegrity,
            citizenAssertion: $citizenAssertion,
            disclosureCopy: $disclosureCopy,
            documentAnchor: $documentAnchor,
            consentAnchor: $consentAnchor,
            cellLabelBinding: $cellLabelBinding,
            overall: $overall,
            notes: $notes,
        );
    }

    /**
     * RECORDED: verify the Ed25519 proof over the canonical receipt body.
     *
     * @param array<array-key,mixed> $r
     * @param list<string> $notes
     */
    private function verifyCompanySignature(array $r, array &$notes, string $type): string
    {
        $issuer = self::asString($r['issuer'] ?? null);
        $proof = self::asObject(self::asArray($r['proof'] ?? null)[0] ?? null);
        $vm = self::asString($proof['verificationMethod'] ?? null);
        $proofValue = self::asString($proof['proofValue'] ?? null);

        if ($issuer === '' || $proofValue === '') {
            $notes[] = 'Company signature check FAILED: the receipt is missing its issuer or proofValue.';
            return 'fail';
        }

        $body = $r;
        unset($body['proof']);
        try {
            $canonical = $this->canonicalizer->encode($body);
        } catch (Throwable $e) {
            throw new MalformedInput("the receipt body cannot be canonicalized ({$e->getMessage()})");
        }

        try {
            $signature = Multibase::decodeBytes($proofValue);
        } catch (Throwable) {
            $notes[] = 'Company signature check FAILED: the proofValue is not a valid multibase signature.';
            return 'fail';
        }

        $key = $this->resolveEd25519Key($issuer, $vm);
        if ($key === null) {
            $notes[] = "Company signature UNVERIFIABLE: could not resolve the issuer DID {$issuer} (key {$vm}) to an Ed25519 key "
                . '(network/resolution failure or key not found). This is INCONCLUSIVE, NOT a signature mismatch.';
            return 'unavailable';
        }

        if (Signature::verifyEd25519($key, $canonical, $signature)) {
            $notes[] = "Company signature verified against issuer DID {$issuer} (key {$vm}).";
            if ($type === 'company_documented') {
                $scriptVersion = self::asString(self::asObject($r['evidence'] ?? null)['scriptVersion'] ?? null);
                $notes[] = 'This proves the ORGANISATION documented a consent given by telephone under script version '
                    . "{$scriptVersion}. It does NOT prove the call happened, and there is no signed document: this is the "
                    . 'weakest of the three tiers, so overall is at most PARTIAL.';
            } else {
                $pdfHash = self::asString(self::asObject($r['evidence'] ?? null)['pdfHash'] ?? null);
                $notes[] = "This proves the company ATTESTED to a hand-signed PDF (hash {$pdfHash}); it does NOT prove a human signed.";
            }
            return 'pass';
        }

        $notes[] = "Company signature check FAILED against issuer DID {$issuer} (key {$vm}): the canonical receipt body does not "
            . 'match the signature. The receipt was altered after signing or the wrong key was resolved.';
        return 'fail';
    }

    /**
     * PASSKEY BUNDLE: proof[0] is the company's Ed25519 signature over JCS(signedOffer).
     *
     * @param array<array-key,mixed> $r
     * @param array<array-key,mixed> $offer
     * @param list<string> $notes
     */
    private function verifyOfferSignature(array $r, array $offer, array &$notes): string
    {
        $issuer = self::asString($r['issuer'] ?? null);
        $proof = self::asObject(self::asArray($r['proof'] ?? null)[0] ?? null);
        $vm = self::asString($proof['verificationMethod'] ?? null);
        $proofValue = self::asString($proof['proofValue'] ?? null);

        if ($issuer === '' || $proofValue === '' || ($proof['cryptosuite'] ?? null) !== 'eddsa-jcs-2022') {
            $notes[] = 'Company signature check FAILED: proof[0] is not an eddsa-jcs-2022 signature with an issuer and a proofValue.';
            return 'fail';
        }
        if (explode('#', $vm)[0] !== $issuer) {
            $notes[] = "Company signature check FAILED: proof[0] names the key {$vm}, which does not belong to the issuer {$issuer}.";
            return 'fail';
        }
        try {
            $canonical = $this->canonicalizer->encode($offer);
        } catch (Throwable $e) {
            throw new MalformedInput("the signed offer cannot be canonicalized ({$e->getMessage()})");
        }
        try {
            $signature = Multibase::decodeBytes($proofValue);
        } catch (Throwable) {
            $notes[] = 'Company signature check FAILED: the proofValue is not a valid multibase signature.';
            return 'fail';
        }
        $key = $this->resolveEd25519Key($issuer, $vm);
        if ($key === null) {
            $notes[] = "Company signature UNVERIFIABLE: could not resolve the issuer DID {$issuer} (key {$vm}) to an Ed25519 key "
                . '(network/resolution failure or key not found). This is INCONCLUSIVE, NOT a signature mismatch.';
            return 'unavailable';
        }
        if (Signature::verifyEd25519($key, $canonical, $signature)) {
            $notes[] = "Company signature over the signed offer verified against issuer DID {$issuer} (key {$vm}).";
            return 'pass';
        }
        $notes[] = "Company signature check FAILED against issuer DID {$issuer} (key {$vm}): the signed offer does not match the "
            . 'signature. The offer was altered after signing or the wrong key was resolved.';
        return 'fail';
    }

    /**
     * The WebAuthn signature over authenticatorData || sha256(clientDataJSON), and clientDataJSON's own challenge.
     *
     * @param array<array-key,mixed> $r
     * @param list<string> $notes
     * @return array{status:string, vm:string, challenge:string, standing:string}
     */
    private function verifyPasskeySignature(array $r, array &$notes): array
    {
        $citizenDid = self::asString(self::asObject($r['credentialSubject'] ?? null)['id'] ?? null);
        $proof = self::findWebAuthnProof(self::asArray($r['proof'] ?? null));
        if ($proof === null || $citizenDid === '') {
            $notes[] = 'Citizen assertion FAILED: no WebAuthn assertion or citizen DID on the receipt.';
            return ['status' => 'fail', 'vm' => '', 'challenge' => '', 'standing' => ''];
        }
        $vm = self::asString($proof['verificationMethod'] ?? null);
        $challenge = self::asString($proof['challenge'] ?? null);
        if (preg_match(self::CITIZEN_DID_PATTERN, $citizenDid) !== 1) {
            $notes[] = "Citizen assertion UNVERIFIABLE: the subject {$citizenDid} is not an Agreely citizen DID (" . self::CITIZEN_DID_PREFIX . '...), '
                . 'so no passkey key can be confirmed for it. Its key was not looked up; the assertion proves nothing.';
            return ['status' => 'unavailable', 'vm' => $vm, 'challenge' => $challenge, 'standing' => ''];
        }
        $doc = $this->resolveDid($citizenDid);
        if ($doc === null) {
            $notes[] = "Citizen assertion UNVERIFIABLE: could not resolve the citizen DID {$citizenDid} (passkey {$vm}) "
                . '(network/resolution failure or key not found). This is INCONCLUSIVE, NOT a signature mismatch.';
            return ['status' => 'unavailable', 'vm' => $vm, 'challenge' => $challenge, 'standing' => ''];
        }
        if (!self::isPlainObject($doc) || ($doc['id'] ?? null) !== $citizenDid
            || !isset($doc['verificationMethod']) || !is_array($doc['verificationMethod']) || !array_is_list($doc['verificationMethod'])) {
            $notes[] = "Citizen assertion UNVERIFIABLE: what the resolver answered for {$citizenDid} is not that identity's DID "
                . 'document (another id, or no verificationMethod list). This is INCONCLUSIVE, NOT a signature mismatch.';
            return ['status' => 'unavailable', 'vm' => $vm, 'challenge' => $challenge, 'standing' => ''];
        }
        $key = self::citizenKeyOf($doc, $vm);
        if ($key === null) {
            $notes[] = "Citizen assertion FAILED: the DID document of {$citizenDid} holds no passkey (COSE) key.";
            return ['status' => 'fail', 'vm' => $vm, 'challenge' => $challenge, 'standing' => ''];
        }
        $coseHex = $key['cose'];

        try {
            $cose = Cose::parseKey(self::hexToBin($coseHex));
            $authData = self::base64UrlDecode(self::asString($proof['authenticatorData'] ?? null));
            $clientDataJSON = self::base64UrlDecode(self::asString($proof['clientDataJSON'] ?? null));
            $signature = self::base64UrlDecode(self::asString($proof['signature'] ?? null));
        } catch (Throwable) {
            $notes[] = 'Citizen assertion FAILED: the assertion artifacts could not be decoded.';
            return ['status' => 'fail', 'vm' => $vm, 'challenge' => $challenge, 'standing' => ''];
        }

        if ($cose['alg'] === 'unsupported') {
            $notes[] = 'Citizen assertion UNSUPPORTED: the passkey uses a COSE algorithm this offline verifier does not implement.';
            return ['status' => 'unsupported', 'vm' => $vm, 'challenge' => $challenge, 'standing' => ''];
        }
        if (!self::clientDataChallengeMatches($clientDataJSON, $challenge)) {
            $notes[] = "Citizen assertion FAILED: the signed clientDataJSON is not a webauthn.get over the proof's challenge.";
            return ['status' => 'fail', 'vm' => $vm, 'challenge' => $challenge, 'standing' => ''];
        }
        if (!Signature::verifyWebAuthnAssertion($cose, $authData, $clientDataJSON, $signature)) {
            $notes[] = 'Citizen assertion FAILED: the WebAuthn signature did not verify against the resolved passkey.';
            return ['status' => 'fail', 'vm' => $vm, 'challenge' => $challenge, 'standing' => ''];
        }
        $grantedAt = self::asString(self::asObject(self::asObject($r['credentialSubject'] ?? null)['consent'] ?? null)['grantedAt'] ?? null);
        return [
            'status' => 'pass',
            'vm' => $key['vmId'],
            'challenge' => $challenge,
            'standing' => self::citizenKeyStanding($doc, $citizenDid, $coseHex, $grantedAt),
        ];
    }

    /**
     * RECORDED: fetch the IPFS document body and match its disclosure to disclosureHash. On a recorded
     * receipt the disclosureHash and a v2 legalBasis sit inside the company-signed body. v2 hashes
     * {disclosure, legalBasis}; v1 (or absent) hashes the disclosure map alone.
     *
     * @param array<array-key,mixed> $r
     * @param list<string> $notes
     */
    private function verifyRecordedDisclosureCopy(array $r, array &$notes): string
    {
        $consent = self::asObject(self::asObject($r['credentialSubject'] ?? null)['consent'] ?? null);
        $cid = self::asString(self::asObject($consent['document'] ?? null)['ipfsCid'] ?? null);
        $disclosureHash = self::asString($consent['disclosureHash'] ?? null);

        if ($cid === '' || $disclosureHash === '') {
            $notes[] = 'disclosureCopy skipped: the receipt carries no document.ipfsCid + disclosureHash pair to fetch and compare.';
            return 'skipped';
        }
        if (($this->opts['verifyDisclosure'] ?? false) !== true) {
            $notes[] = self::disclosureNotRequestedNote();
            return 'skipped';
        }

        // A declared disclosureVersion of null is unknown, not absent: only a missing key means v1.
        $versionDeclared = array_key_exists('disclosureVersion', $consent);
        $version = $consent['disclosureVersion'] ?? null;
        $receiptBasis = $consent['legalBasis'] ?? null;
        if ($versionDeclared && $version !== '1' && $version !== '2') {
            $notes[] = 'disclosureCopy FAILED: the receipt declares an unknown disclosureVersion, so its preimage is not defined.';
            return 'fail';
        }
        if ($version !== '2' && $receiptBasis !== null) {
            $notes[] = 'disclosureCopy FAILED: the receipt carries a legalBasis but no disclosureVersion 2, so the preimage it hashes is ambiguous.';
            return 'fail';
        }
        if ($version === '2' && !self::isPlainObject($receiptBasis)) {
            // A v2 receipt is never checked with the v1 formula: a missing basis fails here, it does not fall back.
            $notes[] = 'disclosureCopy FAILED (disclosureVersion 2): the receipt declares a v2 disclosureHash but carries no legalBasis map, so the preimage cannot be rebuilt.';
            return 'fail';
        }
        return $this->compareDisclosureCopy($cid, $disclosureHash, $version === '2' ? $receiptBasis : null, $notes);
    }

    /**
     * PASSKEY BUNDLE: the copy is compared with the SIGNED offer's disclosureHash and legalBasis, never the receipt's.
     *
     * @param array<array-key,mixed> $offer
     * @param list<string> $notes
     */
    private function verifyOfferDisclosureCopy(array $offer, string $companySignature, array &$notes): string
    {
        $cid = self::asString(self::asObject($offer['document'] ?? null)['ipfsCid'] ?? null);
        $disclosureHash = self::asString($offer['disclosureHash'] ?? null);
        if ($cid === '' || $disclosureHash === '') {
            $notes[] = 'disclosureCopy skipped: the signed offer carries no document.ipfsCid + disclosureHash pair to fetch and compare.';
            return 'skipped';
        }
        if (($this->opts['verifyDisclosure'] ?? false) !== true) {
            $notes[] = self::disclosureNotRequestedNote();
            return 'skipped';
        }
        if ($companySignature !== 'pass') {
            $notes[] = 'disclosureCopy skipped: the company signature over the offer did not verify, so there is no signed disclosureHash to compare the copy with.';
            return 'skipped';
        }
        return $this->compareDisclosureCopy($cid, $disclosureHash, $offer['legalBasis'] ?? null, $notes);
    }

    /**
     * Fetch the IPFS body and compare sha256(JCS(preimage)) with `$disclosureHash`. `$basis` non-null = the v2 preimage.
     *
     * @param list<string> $notes
     */
    private function compareDisclosureCopy(string $cid, string $disclosureHash, mixed $basis, array &$notes): string
    {
        $fetched = $this->fetch($this->ipfsGateway($cid));
        if ($fetched['status'] !== null) {
            $notes[] = "disclosureCopy UNAVAILABLE: the IPFS gateway answered HTTP {$fetched['status']} for CID {$cid}. This is NOT a hash mismatch.";
            return 'unavailable';
        }
        $body = $fetched['body'] === null ? null : self::decodeJson($fetched['body']);
        if ($body === null) {
            $notes[] = "disclosureCopy UNAVAILABLE: fetching the IPFS body for CID {$cid} failed or timed out. This is NOT a hash mismatch.";
            return 'unavailable';
        }
        $body = $body['value'];

        // The pinned IPFS body wraps the disclosure map under `disclosure` and carries NO legalBasis today
        // (IpfsDocumentService::canonicalPayload). If a body ever carries one, it must agree with the signed one.
        $bodyObject = self::asObject($body);
        $disclosure = self::asObject($bodyObject['disclosure'] ?? null);
        try {
            if ($basis !== null) {
                if (
                    array_key_exists('legalBasis', $bodyObject)
                    && $this->canonicalizer->encode($bodyObject['legalBasis']) !== $this->canonicalizer->encode($basis)
                ) {
                    $notes[] = 'disclosureCopy FAILED (disclosureVersion 2): the IPFS document body carries a legalBasis that differs from the signed one.';
                    return 'fail';
                }
                $computed = $this->disclosureHashOf(['disclosure' => $disclosure, 'legalBasis' => $basis]);
            } else {
                $computed = $this->disclosureHashOf($disclosure);
            }
        } catch (Throwable $e) {
            $notes[] = "disclosureCopy FAILED: the IPFS document body or the legalBasis cannot be canonicalized ({$e->getMessage()}), so the copy cannot be checked.";
            return 'fail';
        }
        $label = $basis !== null ? ' (disclosureVersion 2: {disclosure, legalBasis})' : '';
        if ($computed === $disclosureHash) {
            $notes[] = "Disclosure copy verified{$label}: the IPFS document body matches the signed disclosureHash {$disclosureHash}.";
            return 'pass';
        }
        $notes[] = "disclosureCopy FAILED{$label}: the IPFS document body hashes to {$computed}, not the signed disclosureHash {$disclosureHash}.";
        return 'fail';
    }

    /** "0x" + sha256(JCS(preimage)): the disclosureHash formula the app seals (ConsentRequestService). */
    private function disclosureHashOf(mixed $preimage): string
    {
        return '0x' . hash('sha256', $this->canonicalizer->encode($preimage));
    }

    /**
     * OPT-IN (rpcUrl): is the document CID anchored (IdentityAnchored)? The event is emit-only, so this is
     * a log query from the registry's deploy block, paged under a provider's range limit. "fail" only when
     * the chain answered for EVERY block of that range; any refusal, error or budget exhaustion is "unavailable".
     *
     * @param array<array-key,mixed> $r
     * @param list<string> $notes
     */
    private function verifyDocumentAnchor(array $r, array &$notes): string
    {
        $rpcUrl = self::asString($this->opts['rpcUrl'] ?? null);
        if ($rpcUrl === '') {
            $notes[] = 'documentAnchor skipped: pass an rpcUrl to check the on-chain document anchor.';
            return 'skipped';
        }
        $consent = self::asObject(self::asObject($r['credentialSubject'] ?? null)['consent'] ?? null);
        $cid = self::asString(self::asObject($consent['document'] ?? null)['ipfsCid'] ?? null);
        if ($cid === '') {
            $notes[] = 'documentAnchor skipped: the receipt carries no document.ipfsCid to look up on-chain.';
            return 'skipped';
        }
        $chainId = $this->chainId();
        $registry = $this->registry();
        if ($registry === null) {
            $notes[] = "documentAnchor skipped: no AgreelyRegistry address is known for chainId {$chainId}.";
            return 'skipped';
        }

        $head = $this->rpc($rpcUrl, 'eth_blockNumber', []);
        $latest = $head['ok'] ? self::parseQuantity($head['result']) : null;
        if (!$head['ok'] || $latest === null) {
            $reason = $head['ok'] ? 'the RPC answered an unreadable block number' : $head['reason'];
            $notes[] = "documentAnchor UNAVAILABLE: {$reason}. This is NOT a missing anchor.";
            return 'unavailable';
        }
        $deployBlock = $this->intOpt('registryDeployBlock') ?? self::DEPLOY_BLOCK_BY_CHAIN[$chainId] ?? 0;
        $from = min($deployBlock, $latest);
        $topics = [Keccak::hashHex('IdentityAnchored(bytes32,uint64)'), Keccak::hashHex($cid)];
        $found = "Document anchor FOUND on chainId {$chainId}: CID {$cid} existed at anchor time. "
            . 'This proves the DOCUMENT existed, NOT that any consent was given.';
        $absent = "documentAnchor FAILED: the chain answered for every block from {$from} to {$latest} "
            . "and holds no anchor for CID {$cid} on chainId {$chainId}.";

        // One full-range query first: a provider without a range limit answers it outright.
        $full = $this->getLogs($rpcUrl, $registry, $from, $latest, $topics);
        if ($full['ok']) {
            if (!is_array($full['result']) || !array_is_list($full['result'])) {
                $notes[] = 'documentAnchor UNAVAILABLE: the RPC answered the log query with something other than a list. This is NOT a missing anchor.';
                return 'unavailable';
            }
            $notes[] = count($full['result']) > 0 ? $found : $absent;
            return count($full['result']) > 0 ? 'pass' : 'fail';
        }

        // Refused (range limit, archive token): page backwards from the head, newest first.
        $range = self::positiveInt($this->opts['logRangeLimit'] ?? null, self::DEFAULT_LOG_RANGE_LIMIT);
        $budget = self::positiveInt($this->opts['maxLogQueries'] ?? null, self::DEFAULT_MAX_LOG_QUERIES);
        $hi = $latest;
        for ($q = 0; $q < $budget && $hi >= $from; $q++) {
            $lo = max($from, $hi - $range + 1);
            $page = $this->getLogs($rpcUrl, $registry, $lo, $hi, $topics);
            if (!$page['ok'] || !is_array($page['result']) || !array_is_list($page['result'])) {
                $pageReason = $page['ok'] ? 'an answer that is not a list' : $page['reason'];
                $notes[] = "documentAnchor UNAVAILABLE: the RPC refused the full-range log query ({$full['reason']}) and a paged query "
                    . "({$pageReason}). This is NOT a missing anchor.";
                return 'unavailable';
            }
            if (count($page['result']) > 0) {
                $notes[] = $found;
                return 'pass';
            }
            $hi = $lo - 1;
        }
        if ($hi < $from) {
            $notes[] = $absent;
            return 'fail';
        }
        $next = $hi + 1;
        $notes[] = "documentAnchor UNAVAILABLE: no anchor for CID {$cid} in blocks {$next} to {$latest}, the "
            . "{$budget} paged queries this RPC allows ({$full['reason']}); blocks {$from} to {$hi} were "
            . 'not read. This is NOT a missing anchor: use an RPC that serves the full range, or raise maxLogQueries.';
        return 'unavailable';
    }

    /**
     * OPT-IN (rpcUrl), BUNDLE ONLY: eth_call registry(consentRef) for each cell's consentRef, as the app's
     * chain.js reads it. The record is tied to this receipt only through the Merkle root, which is trusted
     * only once the passkey assertion is bound to it.
     *
     * @param array<array-key,mixed> $bundle
     * @param list<string> $notes
     */
    private function verifyConsentAnchor(array $bundle, string $merkleRoot, string $citizenAssertion, array &$notes): string
    {
        $rpcUrl = self::asString($this->opts['rpcUrl'] ?? null);
        if ($rpcUrl === '') {
            $notes[] = "consentAnchor skipped: pass an rpcUrl to read the consent's on-chain record.";
            return 'skipped';
        }
        $refs = self::collectConsentRefs($bundle);
        if ($refs === []) {
            $notes[] = 'consentAnchor skipped: the bundle carries no consentRef to read on chain.';
            return 'skipped';
        }
        if ($citizenAssertion !== 'pass') {
            $notes[] = "consentAnchor skipped: the bundle's Merkle root is not bound by a verified passkey assertion, so no on-chain "
                . 'record can be tied to this receipt.';
            return 'skipped';
        }
        $chainId = $this->chainId();
        $registry = $this->registry();
        if ($registry === null) {
            $notes[] = "consentAnchor skipped: no AgreelyRegistry address is known for chainId {$chainId}.";
            return 'skipped';
        }

        $root = strtolower(self::stripHex($merkleRoot));
        $legacy = in_array($chainId, self::LEGACY_LAYOUT_CHAINS, true);
        $selector = substr(Keccak::hashHex('registry(bytes32)'), 0, 10);
        $active = 0;
        $withdrawn = 0;
        $pending = 0;
        $unread = 0;
        $foreign = [];
        foreach ($refs as $ref) {
            $call = $this->rpc($rpcUrl, 'eth_call', [['to' => $registry, 'data' => $selector . self::stripHex($ref)], 'latest']);
            $rec = $call['ok'] ? self::decodeAnchorRecord($call['result'], $legacy) : null;
            $revoked = $rec !== null && ($rec['state'] === 2 || $rec['revokedAt'] > 0);
            // An anchoredAt with neither state Active nor Revoked is a garbage record, not a pending anchor.
            if ($rec === null || ($rec['anchoredAt'] > 0 && !$revoked && $rec['state'] !== 1)) {
                $unread++;
            } elseif ($rec['anchoredAt'] === 0) {
                $pending++;
            } elseif (strtolower(self::stripHex($rec['merkleRoot'])) !== $root) {
                $foreign[] = $ref;
            } elseif ($revoked) {
                $withdrawn++;
            } else {
                $active++;
            }
        }

        if ($foreign !== []) {
            $notes[] = "consentAnchor FAILED: the chain records consentRef {$foreign[0]} under a different Merkle root than this "
                . "bundle's, so the bundle's chain reference does not belong to this receipt.";
            return 'fail';
        }
        $total = count($refs);
        $counts = "{$total} cell(s): {$active} active, {$withdrawn} withdrawn, {$pending} not anchored yet, {$unread} unreadable";
        if ($active + $withdrawn !== $total) {
            $notes[] = "consentAnchor UNAVAILABLE on chainId {$chainId} ({$counts}). Anchoring is asynchronous and an RPC can "
                . 'fail: this is NOT a contradiction.';
            return 'unavailable';
        }
        $notes[] = "Consent anchor FOUND on chainId {$chainId} under this bundle's Merkle root ({$counts}). A withdrawn "
            . 'cell is still authentic: check it before relying on the consent as current.';
        return 'pass';
    }

    /**
     * Resolve a company slug to its did:web document (primitive).
     *
     * @return array<array-key,mixed>|null
     */
    public function resolveCompanyDid(string $slug): ?array
    {
        $host = self::asString($this->opts['companyDidHost'] ?? null) ?: self::DEFAULT_COMPANY_HOST;
        return $this->resolveDid("did:web:{$host}:c:{$slug}");
    }

    private function chainId(): int
    {
        return $this->intOpt('chainId') ?? self::DEFAULT_CHAIN_ID;
    }

    private function registry(): ?string
    {
        $address = $this->opts['registryAddress'] ?? null;
        if (is_string($address)) {
            return $address;
        }
        return self::REGISTRY_BY_CHAIN[$this->chainId()] ?? null;
    }

    private function intOpt(string $name): ?int
    {
        $v = $this->opts[$name] ?? null;
        return is_int($v) ? $v : null;
    }

    /**
     * @param list<string> $topics
     * @return array{ok:true, result:mixed}|array{ok:false, reason:string}
     */
    private function getLogs(string $rpcUrl, string $registry, int $from, int $to, array $topics): array
    {
        return $this->rpc($rpcUrl, 'eth_getLogs', [[
            'address' => $registry,
            'fromBlock' => self::toQuantity($from),
            'toBlock' => self::toQuantity($to),
            'topics' => $topics,
        ]]);
    }

    /**
     * One JSON-RPC round trip, inside the verification's overall deadline. Every transport, HTTP or
     * JSON-RPC failure, and a passed deadline, is an outcome, never a throw.
     *
     * @param list<mixed> $params
     * @return array{ok:true, result:mixed}|array{ok:false, reason:string}
     */
    private function rpc(string $rpcUrl, string $method, array $params): array
    {
        $this->rpcDeadline ??= hrtime(true)
            + self::positiveInt($this->opts['rpcDeadlineMs'] ?? null, self::DEFAULT_RPC_DEADLINE_MS) * 1_000_000;
        $remainingMs = intdiv($this->rpcDeadline - hrtime(true), 1_000_000);
        if ($remainingMs <= 0) {
            return ['ok' => false, 'reason' => 'the overall RPC deadline passed before this call (rpcDeadlineMs)'];
        }
        $payload = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_UNESCAPED_SLASHES);
        $text = is_string($payload) ? $this->httpPost($rpcUrl, $payload, $remainingMs) : null;
        $decoded = $text === null ? null : self::decodeJson($text);
        if ($decoded === null) {
            return ['ok' => false, 'reason' => self::RPC_TRANSPORT_FAILURE];
        }
        $json = self::asObject($decoded['value']);
        if (array_key_exists('error', $json)) {
            $message = self::asString(self::asObject($json['error'])['message'] ?? null) ?: 'no message';
            return ['ok' => false, 'reason' => "the RPC refused the query (JSON-RPC error: {$message})"];
        }
        if (!array_key_exists('result', $json)) {
            return ['ok' => false, 'reason' => 'the RPC answered without a result'];
        }
        return ['ok' => true, 'result' => $json['result']];
    }

    private function resolveEd25519Key(string $issuer, string $vmId): ?string
    {
        $doc = $this->resolveDid($issuer);
        $vm = self::pickVerificationMethod($doc, $vmId);
        $mb = self::asString($vm['publicKeyMultibase'] ?? null);
        if ($mb === '') {
            return null;
        }
        try {
            return Multibase::decodeEd25519PublicKey($mb);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<array-key,mixed>|null */
    private function resolveDid(string $did): ?array
    {
        $resolver = $this->opts['resolver'] ?? null;
        if (is_callable($resolver)) {
            try {
                // A throwing resolver is a resolution FAILURE, not a tamper: null
                // flows through to an "unavailable" check, never a "fail".
                $doc = $resolver($did);
            } catch (Throwable) {
                return null;
            }
            return self::didDocumentOf($doc);
        }
        // Default HTTPS resolver: did:web -> /c/{slug}/did.json; did:agreely -> /did/{did}.
        if (str_starts_with($did, 'did:web:')) {
            $parts = explode(':', substr($did, strlen('did:web:')));
            $host = rawurldecode($parts[0]);
            $path = implode('/', array_map('rawurldecode', array_slice($parts, 1)));
            $url = "https://{$host}/{$path}/did.json";
        } else {
            $base = rtrim(self::asString($this->opts['citizenResolverBaseUrl'] ?? null) ?: self::DEFAULT_CITIZEN_BASE, '/');
            $url = "{$base}/did/" . rawurlencode($did);
        }
        return self::didDocumentOf($this->fetch($url)['body']);
    }

    /**
     * A resolved DID document as JSON text, a json_decode() object or an array, read with exactJson().
     *
     * @return array<array-key,mixed>|null
     */
    private static function didDocumentOf(mixed $doc): ?array
    {
        if (is_string($doc)) {
            $decoded = self::decodeJson($doc);
            if ($decoded === null) {
                return null;
            }
            $doc = $decoded['value'];
        }
        if (!is_array($doc) && !$doc instanceof stdClass) {
            return null;
        }
        $doc = self::exactJson($doc);
        return is_array($doc) || $doc instanceof stdClass ? (array) $doc : null;
    }

    private function ipfsGateway(string $cid): string
    {
        $gw = $this->opts['ipfsGateway'] ?? null;
        if (is_callable($gw)) {
            $out = $gw($cid);
            return is_string($out) ? $out : self::DEFAULT_IPFS_GATEWAY . $cid;
        }
        return self::DEFAULT_IPFS_GATEWAY . $cid;
    }

    /**
     * GET `$url`. `status` is set only when the server answered with an HTTP error, so a gateway error page is
     * never mistaken for the document. An injected httpGet reports any failure as null.
     *
     * @return array{body:?string, status:?int}
     */
    private function fetch(string $url): array
    {
        $get = $this->opts['httpGet'] ?? null;
        if (is_callable($get)) {
            try {
                $out = $get($url);
            } catch (Throwable) {
                return ['body' => null, 'status' => null];
            }
            return ['body' => is_string($out) ? $out : null, 'status' => null];
        }
        return $this->curl('GET', $url, null);
    }

    private function httpPost(string $url, string $body, int $remainingMs): ?string
    {
        $post = $this->opts['httpPost'] ?? null;
        if (is_callable($post)) {
            try {
                $out = $post($url, $body);
            } catch (Throwable) {
                return null;
            }
            return is_string($out) ? $out : null;
        }
        return $this->curl('POST', $url, $body, $remainingMs)['body'];
    }

    /**
     * @param 'GET'|'POST' $method
     * @return array{body:?string, status:?int}
     */
    private function curl(string $method, string $url, ?string $body, ?int $capMs = null): array
    {
        if ($url === '') {
            return ['body' => null, 'status' => null];
        }
        $ch = curl_init();
        $headers = $body !== null ? ['Accept: application/json', 'Content-Type: application/json'] : ['Accept: application/json'];
        $timeoutMs = self::positiveInt($this->opts['timeoutMs'] ?? null, self::DEFAULT_TIMEOUT_MS);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => $capMs === null ? $timeoutMs : min($timeoutMs, $capMs),
        ]);
        // TRAP: never spread [CURLOPT_POSTFIELDS => ...] into the array above. Its int key is renumbered,
        // and curl silently sends the POST with no body.
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status >= 400) {
            return ['body' => null, 'status' => $status];
        }
        if (!is_string($result) || $status === 0) {
            return ['body' => null, 'status' => null];
        }
        return ['body' => $result, 'status' => null];
    }

    // --- helpers -------------------------------------------------------------

    /** @param array<array-key,mixed> $r */
    private static function receiptType(array $r): string
    {
        $types = self::asArray($r['type'] ?? null);
        // Verbal first: a verbal receipt must never be read as a paper one, nor fall into
        // the citizen path (no passkey proof there reads as a false "failed").
        if (in_array('VerbalConsentReceipt', $types, true)) {
            return 'company_documented';
        }
        if (self::asString($r['assuranceLevel'] ?? null) === 'company_documented') {
            return 'company_documented';
        }
        if (in_array('CompanyAttestedConsentReceipt', $types, true)) {
            return 'company_attested';
        }
        if (self::asString($r['assuranceLevel'] ?? null) === 'company_attested') {
            return 'company_attested';
        }
        return 'citizen';
    }

    /**
     * @param list<mixed> $proofs
     * @return array<array-key,mixed>|null
     */
    private static function findWebAuthnProof(array $proofs): ?array
    {
        foreach ($proofs as $p) {
            $obj = self::asObject($p);
            if (self::asString($obj['type'] ?? null) === 'WebAuthnAssertion') {
                return $obj;
            }
        }
        return null;
    }

    /**
     * @param array<array-key,mixed>|null $doc
     * @return array<array-key,mixed>|null
     */
    private static function pickVerificationMethod(?array $doc, string $vmId): ?array
    {
        if ($doc === null || !self::isPlainObject($doc)) {
            return null;
        }
        $methods = array_values(array_filter(
            self::asArray($doc['verificationMethod'] ?? null),
            static fn (mixed $m): bool => is_array($m) && self::isPlainObject($m),
        ));
        foreach ($methods as $m) {
            if (is_array($m) && ($m['id'] ?? null) === $vmId) {
                return $m;
            }
        }
        $first = $methods[0] ?? null;
        return is_array($first) ? $first : null;
    }

    /**
     * The passkey key the assertion is checked with (did.js citizenKeyFromDoc): the method the proof names, else the
     * first one carrying a COSE key. A method listed under capabilityDelegation and not under authentication is
     * never used; one listed under both is.
     *
     * @param array<array-key,mixed> $doc
     * @return array{cose:string, vmId:string}|null
     */
    private static function citizenKeyOf(array $doc, string $vmId): ?array
    {
        $delegation = self::asArray($doc['capabilityDelegation'] ?? null);
        $auth = self::asArray($doc['authentication'] ?? null);
        $usable = static function (mixed $m) use ($delegation, $auth): bool {
            if (!self::isPlainObject($m) || !is_array($m) || !is_string($m['publicKeyCose'] ?? null)) {
                return false;
            }
            $id = $m['id'] ?? null;
            if (!is_string($id)) {
                return true;
            }
            $frag = str_contains($id, '#') ? '#' . explode('#', $id)[1] : '';
            $listedIn = static fn (array $refs): bool => in_array($id, $refs, true) || ($frag !== '' && in_array($frag, $refs, true));
            return !$listedIn($delegation) || $listedIn($auth);
        };
        $methods = self::asArray($doc['verificationMethod'] ?? null);
        $chosen = null;
        foreach ($methods as $m) {
            if ($usable($m) && is_array($m) && ($m['id'] ?? null) === $vmId) {
                $chosen = $m;
                break;
            }
        }
        if ($chosen === null) {
            foreach ($methods as $m) {
                if ($usable($m) && is_array($m)) {
                    $chosen = $m;
                    break;
                }
            }
        }
        if ($chosen === null) {
            return null;
        }
        return ['cose' => self::asString($chosen['publicKeyCose'] ?? null), 'vmId' => self::asString($chosen['id'] ?? null)];
    }

    /**
     * Where the key the assertion verified with stands in the citizen's own DID document (citizen-key.js
     * citizenKeyStandingFromDoc): confirmed only when a method of the same DID, listed under `authentication`, carries
     * exactly that COSE key and its window [validFrom, validUntil) covers grantedAt. The caller has already checked that
     * the document's id is the subject.
     *
     * @param array<array-key,mixed> $doc
     * @return 'confirmed'|'unlisted'|'outsideWindow'|'grantedAtUnreadable'
     */
    private static function citizenKeyStanding(array $doc, string $subject, string $keyCose, string $grantedAt): string
    {
        $auth = self::asArray($doc['authentication'] ?? null);
        $key = self::normHex($keyCose);
        $held = [];
        foreach (self::asArray($doc['verificationMethod'] ?? null) as $m) {
            if (!self::isPlainObject($m) || !is_array($m) || !is_string($m['id'] ?? null)) {
                continue;
            }
            $parts = explode('#', $m['id']);
            $frag = $parts[1] ?? '';
            if ($parts[0] !== $subject || $frag === '') {
                continue;
            }
            if (!in_array($m['id'], $auth, true) && !in_array('#' . $frag, $auth, true)) {
                continue;
            }
            if ($key !== '' && self::normHex($m['publicKeyCose'] ?? null) === $key) {
                $held[] = $m;
            }
        }
        if ($held === []) {
            return 'unlisted';
        }
        $at = self::parseInstant($grantedAt);
        if ($at === null) {
            return 'grantedAtUnreadable';
        }
        foreach ($held as $m) {
            $from = self::parseInstant($m['validFrom'] ?? null);
            if ($from === null || $from > $at) {
                continue;
            }
            $untilRaw = $m['validUntil'] ?? null;
            if ($untilRaw === null) {
                return 'confirmed';
            }
            $until = self::parseInstant($untilRaw);
            if ($until !== null && $at < $until) {
                return 'confirmed';
            }
        }
        return 'outsideWindow';
    }

    /**
     * A company-signed offer bounds when it could be accepted: createdAt <= grantedAt <= expiresAt, compared in whole
     * seconds. An offer without both dates gives no verdict here; a date that is present but unreadable fails.
     * `failure` is the whole integrity note when it fails, `clause` what the integrity note says of it otherwise.
     *
     * @param array<array-key,mixed> $r
     * @param array<array-key,mixed> $offer
     * @return array{failure:?string, clause:string}
     */
    private static function offerWindowOf(array $r, array $offer): array
    {
        $created = $offer['createdAt'] ?? null;
        $expires = $offer['expiresAt'] ?? null;
        if ($created === null || $expires === null) {
            return ['failure' => null, 'clause' => ' The signed offer carries no createdAt and expiresAt pair, so its window was not checked.'];
        }
        $grantedAt = self::asObject(self::asObject($r['credentialSubject'] ?? null)['consent'] ?? null)['grantedAt'] ?? null;
        $seconds = static function (mixed $v): ?int {
            $ms = self::parseInstant($v);
            return $ms === null ? null : (int) floor($ms / 1000);
        };
        $from = $seconds($created);
        $at = $seconds($grantedAt);
        $until = $seconds($expires);
        if ($from === null || $at === null || $until === null) {
            return ['failure' => "Receipt integrity FAILED (offer-window-unreadable): the signed offer's createdAt or expiresAt, or the "
                . "receipt's grantedAt, is not a readable date.", 'clause' => ''];
        }
        if ($at < $from || $at > $until) {
            return ['failure' => 'Receipt integrity FAILED (granted-outside-offer-window): grantedAt ' . self::asString($grantedAt)
                . " lies outside the signed offer's window, from its createdAt " . self::asString($created) . ' to its expiresAt '
                . self::asString($expires) . '.', 'clause' => ''];
        }
        return ['failure' => null, 'clause' => " The receipt's grantedAt lies between the signed offer's createdAt and expiresAt."];
    }

    /** Epoch milliseconds of an instant matching INSTANT_PATTERN on a real calendar day, else null. */
    private static function parseInstant(mixed $v): ?int
    {
        if (!is_string($v) || preg_match(self::INSTANT_PATTERN, $v, $m) !== 1) {
            return null;
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) ($m[6] ?? 0);
        $fractionDigits = $m[7] ?? '';
        $zone = ($m[8] ?? '') === '' ? 'Z' : $m[8];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $offset = 0;
        if ($zone !== 'Z') {
            $oh = (int) substr($zone, 1, 2);
            $om = (int) substr($zone, 4, 2);
            if ($oh > 23 || $om > 59) {
                return null;
            }
            $offset = ($zone[0] === '-' ? -1 : 1) * ($oh * 60 + $om) * 60000;
        }
        $fraction = $fractionDigits === '' ? 0 : (int) substr(substr($fractionDigits, 1) . '00', 0, 3);
        $days = self::daysFromCivil($year, $month, $day);
        return ((($days * 24 + $hour) * 60 + $minute) * 60 + $second) * 1000 + $fraction - $offset;
    }

    /** Days since 1970-01-01 of a proleptic Gregorian date (H. Hinnant's days_from_civil). */
    private static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    private static function normHex(mixed $h): string
    {
        return is_string($h) ? strtolower(self::stripHex($h)) : '';
    }

    /** clientDataJSON is a webauthn.get whose challenge equals the proof's stated challenge. */
    private static function clientDataChallengeMatches(string $clientDataJSON, string $challengeHex): bool
    {
        /** @var mixed $parsed */
        $parsed = json_decode($clientDataJSON, true);
        if (
            !is_array($parsed) || ($parsed['type'] ?? null) !== 'webauthn.get'
            || !isset($parsed['challenge']) || !is_string($parsed['challenge'])
        ) {
            return false;
        }
        try {
            return hash_equals(self::hexToBin($challengeHex), self::base64UrlDecode($parsed['challenge']));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Recompute the passkey challenge exactly as the server did (Commitment::challenge) and require it to
     * equal the challenge the passkey signed: keccak256(JCS({issuer, subject, requestId, action, grantedAt,
     * merkleRoot}) || nonce). Fails closed: a field that cannot be recomputed is "unrecomputable", never a pass.
     *
     * @param array<array-key,mixed> $r
     * @param array<array-key,mixed> $offer
     */
    private function citizenChallengeBinds(array $r, array $offer, string $merkleRoot, string $statedChallenge): string
    {
        $subject = self::asObject($r['credentialSubject'] ?? null);
        $consent = self::asObject($subject['consent'] ?? null);
        $nonce = $offer['nonce'] ?? null;
        if (!is_string($nonce) || $nonce === '' || $merkleRoot === '' || $statedChallenge === '') {
            return 'unrecomputable';
        }
        try {
            $context = $this->canonicalizer->encode([
                'issuer' => $r['issuer'] ?? null,
                'subject' => $subject['id'] ?? null,
                'requestId' => $consent['requestId'] ?? null,
                'action' => $consent['action'] ?? null,
                'grantedAt' => $consent['grantedAt'] ?? null,
                'merkleRoot' => $merkleRoot,
            ]);
            $recomputed = bin2hex(Keccak::hash($context . self::hexToBin($nonce)));
        } catch (Throwable) {
            return 'unrecomputable';
        }
        return $recomputed === strtolower(self::stripHex($statedChallenge)) ? 'ok' : 'mismatch';
    }

    /**
     * The receipt is consistent with the company-signed offer, EXHAUSTIVELY and deny-by-default
     * (verify-core.js receiptMatchesOffer + disclosureHashMatches + validFromMatchesGrantedAt), and its grantedAt lies
     * in the offer's window when `$window` was judged. One note either way.
     *
     * @param ?string $reason integrityFailure(), null when every field matches
     * @param array{failure:?string, clause:string}|null $window
     * @param list<string> $notes
     */
    private static function checkReceiptIntegrity(?string $reason, ?array $window, array &$notes): string
    {
        if ($reason !== null) {
            $notes[] = "Receipt integrity FAILED: {$reason}.";
            return 'fail';
        }
        if ($window !== null && $window['failure'] !== null) {
            $notes[] = $window['failure'];
            return 'fail';
        }
        $notes[] = 'Receipt integrity verified: every field the company-signed offer carries matches the receipt (issuer, '
            . "validUntil, the consent and its cells), the disclosureHash recomputes from the offer's disclosure (and "
            . 'legalBasis), validFrom equals grantedAt, and the receipt carries no field outside the receipt format. '
            . 'The receipt id is not covered by the offer.' . ($window['clause'] ?? '');
        return 'pass';
    }

    /**
     * @param array<array-key,mixed> $r
     * @param array<array-key,mixed> $offer
     */
    private function integrityFailure(array $r, array $offer): ?string
    {
        foreach (array_keys($r) as $k) {
            if (!in_array((string) $k, self::PASSKEY_RECEIPT_KEYS, true)) {
                return "the receipt carries a top-level field outside the receipt format ({$k})";
            }
        }
        if (!$this->canonEqual($r['type'] ?? null, self::PASSKEY_RECEIPT_TYPE)) {
            return 'the receipt type is not a passkey ConsentReceipt';
        }
        if (!$this->canonEqual($r['@context'] ?? null, self::PASSKEY_RECEIPT_CONTEXT)) {
            return 'the receipt @context is not the consent receipt context';
        }
        if (!is_string($r['id'] ?? null) || !str_starts_with($r['id'], 'urn:agreely:receipt:')) {
            return 'the receipt id is not an urn:agreely:receipt identifier';
        }
        $subject = self::asObject($r['credentialSubject'] ?? null);
        foreach (array_keys($subject) as $k) {
            if ($k !== 'id' && $k !== 'consent') {
                return "credentialSubject carries a field the offer does not account for ({$k})";
            }
        }
        $consent = self::asObject($subject['consent'] ?? null);
        foreach (array_keys($consent) as $k) {
            if (!in_array((string) $k, self::ALLOWED_CONSENT_KEYS, true)) {
                return "the consent carries a field the offer does not account for ({$k})";
            }
        }
        $offerConsent = self::asObject($offer['consent'] ?? null);
        if (($r['issuer'] ?? null) !== ($offer['issuer'] ?? null)) {
            return "the issuer differs from the signed offer's";
        }
        if (($r['validUntil'] ?? null) !== self::orEmpty($offer['validUntil'] ?? null)) {
            return "validUntil differs from the signed offer's";
        }
        if (($consent['action'] ?? null) !== ($offerConsent['action'] ?? null)) {
            return "the action differs from the signed offer's";
        }
        if (($consent['requestId'] ?? null) !== self::orEmpty($offer['requestId'] ?? null)) {
            return "the requestId differs from the signed offer's";
        }
        if (!$this->canonEqual($consent['document'] ?? null, $offer['document'] ?? null)) {
            return "the document differs from the signed offer's";
        }
        if (($consent['disclosureHash'] ?? null) !== ($offer['disclosureHash'] ?? null)) {
            return "the disclosureHash differs from the signed offer's";
        }
        if (!$this->itemsAreExactOfferItems($consent['items'] ?? null, $offerConsent['items'] ?? null)) {
            return 'an accepted cell is not an exact item of the signed offer';
        }
        if (array_key_exists('declinedItems', $consent)) {
            if (!$this->itemsAreExactOfferItems($consent['declinedItems'], $offerConsent['items'] ?? null)) {
                return 'a declined cell is not an exact item of the signed offer';
            }
            $accepted = array_map(fn (mixed $i): ?string => $this->canonOrNull($i), self::asArray($consent['items'] ?? null));
            foreach (self::asArray($consent['declinedItems']) as $i) {
                if (in_array($this->canonOrNull($i), $accepted, true)) {
                    return 'a cell is both accepted and declined';
                }
            }
        }
        if (array_key_exists('legalBasis', $consent)) {
            if (!$this->canonEqual($consent['legalBasis'], $offer['legalBasis'] ?? null)) {
                return "the legalBasis differs from the signed offer's";
            }
            if (($consent['disclosureVersion'] ?? null) !== self::orEmpty($offer['disclosureVersion'] ?? null)) {
                return "the disclosureVersion differs from the signed offer's";
            }
        } elseif (isset($offer['legalBasis'])) {
            return 'the signed offer carries a legalBasis the receipt dropped';
        }
        if (array_key_exists('minor', $consent)) {
            if (!$this->canonEqual($consent['minor'], $offer['minor'] ?? null)) {
                return "the minor block differs from the signed offer's";
            }
        } elseif (isset($offer['minor'])) {
            return 'the signed offer carries a minor block the receipt dropped';
        }
        if (!$this->disclosureHashRecomputes($consent, $offer)) {
            return "the disclosureHash does not recompute from the signed offer's disclosure";
        }
        if (!is_string($r['validFrom'] ?? null) || $r['validFrom'] !== ($consent['grantedAt'] ?? null)) {
            return 'validFrom differs from grantedAt';
        }
        return null;
    }

    /**
     * sha256(JCS(preimage)) equals BOTH the receipt's and the offer's stated hash. v2 offer: {disclosure, legalBasis}.
     *
     * @param array<array-key,mixed> $consent
     * @param array<array-key,mixed> $offer
     */
    private function disclosureHashRecomputes(array $consent, array $offer): bool
    {
        $stated = $consent['disclosureHash'] ?? null;
        if (!is_string($stated) || !array_key_exists('disclosure', $offer)) {
            return false;
        }
        $basis = $offer['legalBasis'] ?? null;
        $preimage = $basis !== null ? ['disclosure' => $offer['disclosure'], 'legalBasis' => $basis] : $offer['disclosure'];
        try {
            $computed = $this->disclosureHashOf($preimage);
        } catch (Throwable) {
            return false;
        }
        $offerHash = $offer['disclosureHash'] ?? null;
        return strtolower($stated) === $computed && is_string($offerHash) && strtolower($offerHash) === $computed;
    }

    private function itemsAreExactOfferItems(mixed $receiptItems, mixed $offerItems): bool
    {
        if (
            !is_array($receiptItems) || !array_is_list($receiptItems) || !is_array($offerItems) || !array_is_list($offerItems)
            || $receiptItems === []
        ) {
            return false;
        }
        $offered = array_map(fn (mixed $i): ?string => $this->canonOrNull($i), $offerItems);
        foreach ($receiptItems as $i) {
            $c = $this->canonOrNull($i);
            if ($c === null || !in_array($c, $offered, true)) {
                return false;
            }
        }
        return true;
    }

    private function canonOrNull(mixed $v): ?string
    {
        try {
            return $this->canonicalizer->encode($v);
        } catch (Throwable) {
            return null;
        }
    }

    private function canonEqual(mixed $a, mixed $b): bool
    {
        $ca = $this->canonOrNull($a);
        return $ca !== null && $ca === $this->canonOrNull($b);
    }

    /**
     * Every accepted cell's consentRef (consentRefs), else the legacy single consentRef. De-duped, bytes32 only.
     *
     * @param array<array-key,mixed> $bundle
     * @return list<string>
     */
    private static function collectConsentRefs(array $bundle): array
    {
        $isRef = static fn (mixed $v): bool => is_string($v) && preg_match('/^0x[0-9a-fA-F]{64}$/', $v) === 1;
        $refs = array_values(array_filter(self::asArray($bundle['consentRefs'] ?? null), $isRef));
        if ($refs === [] && $isRef($bundle['consentRef'] ?? null)) {
            $refs = [$bundle['consentRef']];
        }
        /** @var list<string> $refs */
        $lower = array_values(array_unique(array_map('strtolower', $refs)));
        return array_slice($lower, 0, self::MAX_CONSENT_REFS);
    }

    /**
     * Decode `struct Anchor` from registry(bytes32). PACKED (mainnet): merkleRoot, anchoredAt, revokedAt,
     * state. LEGACY (Sepolia): state, merkleRoot, anchoredAt, revokedAt. Null on anything that is not that
     * struct in that layout, so a wrong layout reads as unreadable, never as a contradiction.
     *
     * @return array{state:int, merkleRoot:string, anchoredAt:int, revokedAt:int}|null
     */
    private static function decodeAnchorRecord(mixed $result, bool $legacy): ?array
    {
        $h = strtolower(self::stripHex(self::asString($result)));
        if (preg_match('/^[0-9a-f]{256}/', $h) !== 1) {
            return null;
        }
        $word = static fn (int $i): string => substr($h, $i * 64, 64);
        [$rootW, $anchoredW, $revokedW, $stateW] = $legacy
            ? [$word(1), $word(2), $word(3), $word(0)]
            : [$word(0), $word(1), $word(2), $word(3)];
        if (
            preg_match('/^0{62}[0-9a-f]{2}$/', $stateW) !== 1
            || preg_match('/^0{48}[0-9a-f]{16}$/', $anchoredW) !== 1
            || preg_match('/^0{48}[0-9a-f]{16}$/', $revokedW) !== 1
        ) {
            return null;
        }
        $anchoredAt = self::safeHexInt(substr($anchoredW, 48));
        $revokedAt = self::safeHexInt(substr($revokedW, 48));
        if ($anchoredAt === null || $revokedAt === null) {
            return null;
        }
        return ['state' => (int) hexdec(substr($stateW, 62)), 'merkleRoot' => '0x' . $rootW, 'anchoredAt' => $anchoredAt, 'revokedAt' => $revokedAt];
    }

    /**
     * Is the human-readable cell label (category/purpose) of a RECORDED receipt bound? The labels sit
     * INSIDE the Ed25519-signed body, so this tracks companySignature.
     *
     * @param list<string> $notes
     */
    private static function assessRecordedCellLabels(string $companySignature, array &$notes): string
    {
        if ($companySignature === 'pass') {
            $notes[] = 'Cell labels bound: the category/purpose labels sit inside the company-signed body, so the verified signature '
                . 'covers them. Mutating any item label breaks it.';
            return 'pass';
        }
        if ($companySignature === 'unavailable') {
            $notes[] = 'Cell-label binding UNVERIFIABLE: the issuer DID could not be resolved, so the company signature that binds the '
                . 'category/purpose labels could not be checked.';
            return 'unavailable';
        }
        $notes[] = 'Cell labels UNBOUND: the company signature over the body that carries the category/purpose labels did not verify, '
            . 'so the displayed labels are unproven (possibly tampered).';
        return 'fail';
    }

    /**
     * Why a bundle-shaped input is not a version "1" bundle.
     *
     * @param array<array-key,mixed> $o
     */
    private static function bundleShapeFailure(array $o): string
    {
        if (($o['version'] ?? null) !== self::BUNDLE_VERSION) {
            return 'the bundle version is not the string "' . self::BUNDLE_VERSION . '"';
        }
        if (!self::isPlainObject($o['receipt'] ?? null)) {
            return "the bundle's receipt is missing or not an object";
        }
        return "the bundle's signedOffer is not an object";
    }

    /** Iterative, so a hostile depth cannot exhaust the stack while it is measured. */
    private static function exceedsDepth(mixed $value, int $max): bool
    {
        $stack = [[$value, 1]];
        while ($stack !== []) {
            /** @var array{0:mixed, 1:int} $top */
            $top = array_pop($stack);
            [$v, $depth] = $top;
            if ($v instanceof stdClass) {
                $v = (array) $v;
            }
            if (!is_array($v)) {
                continue;
            }
            if ($depth > $max) {
                return true;
            }
            foreach ($v as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }
        return false;
    }

    /** Every check "skipped": none ran. receiptType is read from the receipt's own type, a best guess. */
    private static function malformedResult(string $input, string $type, string $reason): ReceiptVerification
    {
        return new ReceiptVerification(
            input: $input,
            receiptType: $type,
            companySignature: 'skipped',
            receiptIntegrity: 'skipped',
            citizenAssertion: 'skipped',
            disclosureCopy: 'skipped',
            documentAnchor: 'skipped',
            consentAnchor: 'skipped',
            cellLabelBinding: 'skipped',
            overall: 'malformed',
            notes: [
                "MALFORMED: {$reason}. No check ran: this is a file this verifier cannot read, not evidence that it was "
                    . 'altered. A file Agreely issued is a version "1" bundle or a receipt object.',
            ],
        );
    }

    private static function errorName(Throwable $e): string
    {
        $class = get_class($e);
        $slash = strrpos($class, '\\');
        return $slash === false ? $class : substr($class, $slash + 1);
    }

    private static function disclosureNotRequestedNote(): string
    {
        return 'disclosureCopy skipped: not requested. It fetches the document from a third-party IPFS gateway: pass '
            . 'verifyDisclosure: true to run it.';
    }

    /**
     * JSON-decode `$text` with exactJson(), wrapping the value so a literal `null` body is told apart from unparseable text.
     *
     * @return array{value:mixed}|null
     */
    private static function decodeJson(string $text): ?array
    {
        try {
            return ['value' => self::exactJson(json_decode($text, false, 512, JSON_THROW_ON_ERROR))];
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * A json_decode() value as this verifier reads it: a JSON array is a list, a JSON object a string-keyed array, and an
     * object an array cannot hold as one ({} or keys "0".."n-1") stays a stdClass, which isPlainObject() accepts and
     * asArray() refuses. An array given here keeps its lists as lists: whether one was a JSON object is already lost.
     */
    private static function exactJson(mixed $v): mixed
    {
        if ($v instanceof stdClass) {
            $object = array_map(self::exactJson(...), (array) $v);
            return $object === [] || array_is_list($object) ? (object) $object : $object;
        }
        return is_array($v) ? array_map(self::exactJson(...), $v) : $v;
    }

    private static function parseQuantity(mixed $v): ?int
    {
        if (!is_string($v) || preg_match('/^0x[0-9a-fA-F]+$/', $v) !== 1) {
            return null;
        }
        return self::safeHexInt(substr($v, 2));
    }

    private static function safeHexInt(string $hex): ?int
    {
        $digits = ltrim(strtolower($hex), '0');
        if ($digits === '') {
            return 0;
        }
        if (strlen($digits) > 14) {
            return null;
        }
        $n = (int) hexdec($digits);
        return $n <= self::MAX_SAFE_INTEGER ? $n : null;
    }

    private static function toQuantity(int $n): string
    {
        return '0x' . dechex($n);
    }

    private static function positiveInt(mixed $v, int $fallback): int
    {
        return is_int($v) && $v > 0 ? $v : $fallback;
    }

    /** The value of JavaScript's `v || ""`: a falsy value reads as the empty string. */
    private static function orEmpty(mixed $v): mixed
    {
        return $v === null || $v === false || $v === '' || $v === 0 || $v === 0.0 ? '' : $v;
    }

    private static function stripHex(string $h): string
    {
        return str_starts_with($h, '0x') || str_starts_with($h, '0X') ? substr($h, 2) : $h;
    }

    private static function base64UrlDecode(string $input): string
    {
        $out = base64_decode(strtr($input, '-_', '+/'), true);
        if ($out === false) {
            throw new \RuntimeException('invalid base64url');
        }
        return $out;
    }

    private static function hexToBin(string $input): string
    {
        $hex = self::stripHex($input);
        if ($hex === '' || strlen($hex) % 2 !== 0 || preg_match('/[^0-9a-fA-F]/', $hex) === 1) {
            throw new \RuntimeException('invalid hex');
        }
        $out = hex2bin($hex);
        if ($out === false) {
            throw new \RuntimeException('invalid hex');
        }
        return $out;
    }

    /** A JSON object (see exactJson). In an array decoded by the caller, {} is [], so [] counts as one. */
    private static function isPlainObject(mixed $v): bool
    {
        return $v instanceof stdClass || (is_array($v) && ($v === [] || !array_is_list($v)));
    }

    /** @return array<array-key,mixed> */
    private static function asObject(mixed $v): array
    {
        if ($v instanceof stdClass) {
            return (array) $v;
        }
        return is_array($v) && !array_is_list($v) ? $v : [];
    }

    /** @return list<mixed> */
    private static function asArray(mixed $v): array
    {
        return is_array($v) && array_is_list($v) ? $v : [];
    }

    private static function asString(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
