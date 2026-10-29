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
 * non-object receipt or offer, a passkey receipt carrying a field the issuer never writes, a
 * number, nesting past MAX_INPUT_DEPTH) reads "malformed", and
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
 *   - rpcUrl, registryAddress, chainId: opt-in on-chain documentAnchor and consentAnchor, read by
 *                          eth_call. registryAddress (any address, read by eth_call) defaults to the
 *                          known registry of chainId (Base mainnet and Base Sepolia are known). On any
 *                          other chain, pass it, or the anchor checks read "unavailable"
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
    // The AgreelyRegistry each chain is read from, by eth_call. EIP-55 checksummed.
    //
    // KEEP THIS IN SYNC WITH THE DEPLOYMENTS. It is pinned BY HAND (the SDK reads no config), so a
    // redeploy does NOT propagate here on its own. A stale address is SILENT and WORSE than a missing
    // one: a superseded contract still exists and still answers, it just holds no anchors.
    // RegistryReadsTest pins this map, and refuses every address that is not on it.
    //
    // Each chain reads its one known registry. An explicit registryAddress overrides it on any chain.
    private const REGISTRY_BY_CHAIN = [
        8453 => '0x6F2cF94045cF0cbDD94338882c34D183575023E9', // Base mainnet, deployed at block 53113531
        84532 => '0xf25D3F8E73648C797Be2CCCD428061f357567b9A', // Base Sepolia, deployed at block 50213158
    ];
    // Base mainnet (chainId 8453) is the default chain. Base Sepolia (84532) is an explicit opt-in for testing.
    private const DEFAULT_CHAIN_ID = 8453;
    // rootOf(bytes32) -> (tag, anchoredAt, anchoredBlock, lane). A consent document is keyed keccak256(bytes(cid)),
    // lane 3, always untagged.
    private const DOCUMENT_LANE = 3;
    private const ROOT_LANES = [1 => 'an audit-family root', 2 => 'a cookie consent root'];
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
    // No signature covers a proof's own members, so they are held to the exact shape the issuer writes
    // (CompanyDidService::dataIntegrityProof, ConsentReceipt::build): exactly these keys, every value a string.
    private const ISSUER_PROOF_KEYS = ['type', 'cryptosuite', 'created', 'verificationMethod', 'proofPurpose', 'proofValue'];
    private const CITIZEN_PROOF_KEYS = ['type', 'verificationMethod', 'nonce', 'challenge', 'authenticatorData', 'clientDataJSON', 'signature'];
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
    /**
     * The registry the current bundle names, read only to refuse a mismatch, never to choose what is read.
     *
     * @var array{chainId:string, registry:string}|null
     */
    private ?array $namedRegistry = null;
    /** @var array{anchoredAt:int, anchoredBlock:int}|null */
    private ?array $documentRecord = null;
    /** @var list<array{consentRef:string, state:string, anchoredAt:int, revokedAt:int, anchoredBlock:int}>|null */
    private ?array $consentRecords = null;
    /** @var array{cells:int, active:int, withdrawn:int, pending:int, unreadable:int, anchorBatchRefs?:int}|null */
    private ?array $consentCounts = null;
    /** Set when the bundle names a registry this SDK does not read: the anchors are then never verified. */
    private bool $registryRefused = false;
    /**
     * The company key that verified proof[0], and the DID document it was resolved from.
     *
     * @var array{key:string, doc:array<array-key,mixed>}|null
     */
    private ?array $signer = null;
    /**
     * The consent anchor under the trusted root: its earliest and latest anchor times, when a cell matched it.
     *
     * @var array{earliest:int, latest:int}|null
     */
    private ?array $consentTiming = null;
    private ?string $disclosureGateway = null;

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
        $this->namedRegistry = null;
        $this->documentRecord = null;
        $this->consentRecords = null;
        $this->consentCounts = null;
        $this->registryRefused = false;
        $this->signer = null;
        $this->consentTiming = null;
        $this->disclosureGateway = null;
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
        $this->namedRegistry = $bundle !== null ? self::namedRegistryOf($bundle) : null;
        if ($bundle !== null) {
            $notes[] = 'Read as a verification bundle. Keys are resolved from the issuer and citizen DIDs (or your resolver), '
                . "never taken from the bundle's own didDocuments, which anyone can write.";
        }

        if ($type !== 'citizen') {
            return $this->verifyRecorded($r, $type, $bundle, $notes);
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
        $outside = self::passkeyFieldOutsideFormat($r);
        if ($outside !== null) {
            throw new MalformedInput($outside);
        }
        return $this->verifyPasskeyBundle($r, $bundle, $offer, $merkleRoot, $notes);
    }

    /**
     * A paper (company_attested) or telephone (company_documented) receipt: the company signed the body itself.
     *
     * @param array<array-key,mixed> $r
     * @param 'company_attested'|'company_documented' $type
     * @param array<array-key,mixed>|null $bundle
     * @param list<string> $notes
     */
    private function verifyRecorded(array $r, string $type, ?array $bundle, array $notes): ReceiptVerification
    {
        // verify-core.js receiptModeOf: a version this verifier does not read is refused unread, never judged.
        $shape = Recorded::shapeOf($r, $type);
        if ($shape['kind'] === 'unknown-version') {
            throw new MalformedInput("the receipt's receiptVersion {$shape['version']} is not a version this verifier reads");
        }
        $companySignature = $this->verifyCompanySignature($r, $notes, $type);
        $notes[] = $type === 'company_attested'
            ? 'A company-attested receipt carries no citizen passkey assertion, so citizenAssertion is not applicable.'
            : 'A verbal (company-documented) receipt carries no citizen passkey assertion, so citizenAssertion is not applicable.';
        // The signature is taken over the receipt body itself, so it IS the integrity check, except for the proof's
        // own members, which nothing signs, and a body its issuer never writes, which a valid signature does not make one.
        $proofFailure = self::recordedProofFailure($r);
        $receiptIntegrity = $companySignature;
        if ($proofFailure !== null) {
            $receiptIntegrity = 'fail';
            $notes[] = "Receipt integrity FAILED: {$proofFailure}.";
        }
        if ($shape['kind'] === 'mismatch') {
            $receiptIntegrity = 'fail';
            $notes[] = "Receipt integrity FAILED: {$shape['reason']}, so it is not a receipt its issuer writes.";
        }
        $cellLabelBinding = self::assessRecordedCellLabels($companySignature, $notes);
        $disclosureCopy = $this->verifyRecordedDisclosureCopy($r, $notes);
        $documentAnchor = $this->verifyDocumentAnchor($r, $notes);

        // The issuer is judged FIRST, on its own date: a signed anchor never bounds the instant its own signer chose.
        $company = $this->rpcUrl() !== ''
            ? $this->verifyCompanyKeyRegistry($r, ['anchoredAt' => null, 'issuerDatedOnly' => true], $companySignature, $notes)
            : null;

        $consentAnchor = 'skipped';
        $signedAnchor = null;
        $binding = $bundle !== null && $companySignature === 'pass' && $receiptIntegrity === 'pass'
            ? Recorded::rootBinding($r, $bundle, self::collectConsentRefs($bundle))
            : null;
        if (($r['receiptVersion'] ?? null) !== '4') {
            $notes[] = 'consentAnchor skipped: a paper or telephone receipt signs no anchor before version 4, so the withdrawal of '
                . 'this consent is not checked.';
        } elseif ($bundle === null) {
            $notes[] = "consentAnchor skipped: a version 4 receipt's anchor is read with its verification bundle, which carries the "
                . 'merkleRoot and consentRefs beside it.';
        } elseif ($binding === null || $binding['binding'] === 'preV4') {
            $notes[] = 'consentAnchor skipped: the company signature did not hold, so no signed anchor ties this receipt to the chain.';
        } else {
            $signedAnchor = $binding;
            $consentAnchor = $this->verifySignedAnchor($r, $binding, $company, $notes);
        }
        $withdrawal = $this->withdrawalOf($consentAnchor);

        $decisive = [$companySignature, $receiptIntegrity, $disclosureCopy, $documentAnchor, $consentAnchor, $company['status'] ?? null];
        if (in_array('fail', $decisive, true)) {
            $overall = 'failed';
        } elseif ($companySignature === 'unavailable' || $this->registryRefused || ($company !== null && $company['status'] !== 'pass')) {
            $overall = 'unavailable';
        } elseif ($type === 'company_documented') {
            // A telephone receipt is the organisation's word, authenticated: never more than partial.
            $overall = 'partial';
        } else {
            $overall = $consentAnchor === 'withdrawn' ? 'withdrawn' : 'verified';
        }

        return new ReceiptVerification(
            input: $bundle !== null ? 'bundle' : 'receipt',
            receiptType: $type,
            companySignature: $companySignature,
            receiptIntegrity: $receiptIntegrity,
            citizenAssertion: 'unsupported',
            disclosureCopy: $disclosureCopy,
            documentAnchor: $documentAnchor,
            consentAnchor: $consentAnchor,
            cellLabelBinding: $cellLabelBinding,
            overall: $overall,
            withdrawal: $withdrawal['withdrawal'],
            notes: $notes,
            withdrawnAt: $withdrawal['withdrawnAt'],
            documentAnchorRecord: $this->documentRecord,
            consentAnchorRecords: $this->consentRecords,
            consentAnchorCounts: $this->consentCounts,
            registryRefused: $this->registryRefused,
            disclosureGateway: $this->disclosureGateway,
            companyKeyRegistry: $company['status'] ?? null,
            companyKey: $company['reading'] ?? null,
            signedAnchor: $signedAnchor,
        );
    }

    /**
     * A version 4 recorded receipt's own anchor (ui.js consentChainRequest): a bundle that contradicts the signature
     * fails with nothing read; a bound one is read on the SIGNED root and refs, and only once the registry confirms
     * the company key that signed them. A matching anchor older than the signing instant is not this receipt's.
     *
     * @param array<array-key,mixed> $r
     * @param array{binding:string, merkleRoot:string, consentRefs:list<string>} $binding
     * @param array{status:string, reading?:array<string,mixed>}|null $company
     * @param list<string> $notes
     */
    private function verifySignedAnchor(array $r, array $binding, ?array $company, array &$notes): string
    {
        if ($binding['binding'] === 'contradicts') {
            $notes[] = "consentAnchor FAILED: the receipt signs its anchor batch, and the bundle's own merkleRoot or consentRefs "
                . 'contradict it (another root, or a ref the signature does not carry). Nothing was read on chain.';
            return 'fail';
        }
        if ($binding['binding'] === 'malformed') {
            $notes[] = "consentAnchor skipped: the version 4 receipt's signed anchor is missing or malformed, or the bundle carries "
                . 'no merkleRoot, so no anchor is tied to this receipt.';
            return 'skipped';
        }
        if ($this->rpcUrl() === '') {
            $notes[] = "consentAnchor skipped: pass an rpcUrl to read the consent's on-chain record.";
            return 'skipped';
        }
        if ($company === null || ($company['reading']['verdict'] ?? null) !== 'authentic') {
            $notes[] = "consentAnchor UNAVAILABLE: the receipt's anchor hangs on its company signature, and the registry did not "
                . 'confirm the company key at the instant it signed, so the signed root is not trusted and nothing was read.';
            return 'unavailable';
        }
        $status = $this->verifyConsentAnchor(
            $binding['consentRefs'],
            $binding['merkleRoot'],
            null,
            ['cells' => 0, 'countAnchoredRefs' => false],
            $notes,
        );
        $signedAt = self::secondsOf(self::signingInstantOf($r));
        $timing = $this->consentTiming;
        if (
            in_array($status, ['pass', 'withdrawn', 'unavailable'], true)
            && $timing !== null && $signedAt !== null
            && $timing['earliest'] + CitizenKeyChain::SKEW < $signedAt
        ) {
            $notes[] = "consentAnchor FAILED: the chain anchored this root at {$timing['earliest']}, before the receipt was "
                . "signed: a version 4 receipt signs a root before it is anchored, so this anchor is not this receipt's.";
            return 'fail';
        }
        return $status;
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
            withdrawal: 'not_checked',
            notes: $notes,
            documentAnchorRecord: $this->documentRecord,
            consentAnchorRecords: $this->consentRecords,
            consentAnchorCounts: $this->consentCounts,
            registryRefused: $this->registryRefused,
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

        // The person's key on chain first: a registry that voids it, or places grantedAt outside its window, leaves the
        // bundle's root untrusted, whatever the DID document says (ui.js receiptRootTrusted).
        $rpcUrl = $this->rpcUrl();
        $keyChain = $rpcUrl !== '' && $citizenAssertion === 'pass' ? $this->readCitizenKeyChain($r, $bundle, $passkey['coseHex'] ?? '') : null;
        $keyRefused = $keyChain !== null && ($keyChain['state'] === 'voided' || $keyChain['state'] === 'notInForce');
        $untrusted = null;
        if ($citizenAssertion !== 'pass') {
            $untrusted = "consentAnchor skipped: the bundle's Merkle root is not bound by a verified passkey assertion, so no on-chain "
                . 'record can be tied to this receipt.';
        } elseif ($keyRefused) {
            $untrusted = "consentAnchor skipped: the registry records the person's key as voided, or not in force at grantedAt, so "
                . "the bundle's Merkle root is not trusted and no on-chain record can be tied to this receipt.";
        }
        $consentAnchor = $this->verifyConsentAnchor(
            self::collectConsentRefs($bundle),
            $merkleRoot,
            $untrusted,
            ['cells' => self::acceptedCellCount($r), 'countAnchoredRefs' => true],
            $notes,
        );
        $withdrawal = $this->withdrawalOf($consentAnchor);

        // The company key at grantedAt, bounded by the latest anchor holding the trusted root (issuer.js receiptClockOf).
        $timing = $this->consentTiming;
        $clock = CompanyKey::receiptClockOf($timing !== null ? ['rootMatches' => true, 'anchoredAt' => $timing['latest']] : null);
        $company = $rpcUrl !== '' ? $this->verifyCompanyKeyRegistry($r, $clock, $companySignature, $notes) : null;

        $citizenKeyChain = null;
        $citizenKey = null;
        if ($rpcUrl !== '' && $keyChain === null) {
            $citizenKeyChain = 'skipped';
            $notes[] = "citizenKeyChain skipped: the person's assertion did not verify, so there is no key to look up on chain.";
        } elseif ($keyChain !== null) {
            $half = CitizenKeyChain::halfReading(
                $keyChain['state'],
                'confirmed',
                $timing !== null ? ['rootMatches' => true, 'anchoredAt' => $timing['latest'], 'earliestAnchoredAt' => $timing['earliest']] : null,
                $keyChain['G'],
                $keyChain['keyOfRegistry'],
            );
            $withholds = $half['colour'] === 'amber' && in_array($half['reason'], CitizenKeyChain::CHAIN_WITHHOLDS, true);
            $citizenKey = [
                'chainState' => $keyChain['state'],
                'colour' => $half['colour'],
                'reason' => $half['reason'],
                'keyOfRegistry' => $keyChain['keyOfRegistry'],
                'withholds' => $withholds,
            ];
            if ($keyChain['fp'] !== '') {
                $citizenKey['fingerprint'] = $keyChain['fp'];
            }
            if ($keyChain['record'] !== null) {
                $citizenKey['record'] = $keyChain['record'];
            }
            $citizenKey['grantedAt'] = $keyChain['G'];
            $citizenKey['now'] = $keyChain['now'];
            $citizenKeyChain = match (true) {
                $half['colour'] === 'green' => 'pass',
                $half['colour'] === 'red' => 'fail',
                $half['colour'] === 'neutral' && $keyChain['state'] === 'notLinked' => 'skipped',
                default => 'unavailable',
            };
            $notes[] = CitizenKeyChain::note($keyChain['state'], $half['colour'], $half['reason'], $withholds);
        }

        $checks = [
            $companySignature, $receiptIntegrity, $citizenAssertion, $disclosureCopy, $documentAnchor, $consentAnchor,
            $citizenKeyChain, $company['status'] ?? null,
        ];
        if (in_array('fail', $checks, true)) {
            $overall = 'failed';
        } elseif (
            $companySignature === 'pass' && $receiptIntegrity === 'pass' && $citizenAssertion === 'pass' && !$this->registryRefused
            && ($company === null || $company['status'] === 'pass') && ($citizenKey['withholds'] ?? false) !== true
        ) {
            // A bundle that names a registry this SDK does not read is never verified, however sound its signatures, and
            // with an rpcUrl, neither is one whose company key the registry does not confirm or whose person's key it withholds.
            $overall = $consentAnchor === 'withdrawn' ? 'withdrawn' : 'verified';
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
            withdrawal: $withdrawal['withdrawal'],
            notes: $notes,
            withdrawnAt: $withdrawal['withdrawnAt'],
            documentAnchorRecord: $this->documentRecord,
            consentAnchorRecords: $this->consentRecords,
            consentAnchorCounts: $this->consentCounts,
            registryRefused: $this->registryRefused,
            disclosureGateway: $this->disclosureGateway,
            companyKeyRegistry: $company['status'] ?? null,
            companyKey: $company['reading'] ?? null,
            citizenKeyChain: $citizenKeyChain,
            citizenKey: $citizenKey,
        );
    }

    /**
     * The person's key on chain: one keyOf(fingerprint) on the registry this verifier reads. The salt is the bundle's
     * top-level citizenKeySalt; nothing else in the file chooses what is read or where. Never throws.
     *
     * @param array<array-key,mixed> $r
     * @param array<array-key,mixed> $bundle
     * @return array{state:string, fp:string, record:array{validFrom:string, anchoredAt:string, retiredAt:string, voidedAt:string}|null, G:?int, now:int, keyOfRegistry:bool}
     */
    private function readCitizenKeyChain(array $r, array $bundle, string $coseHex): array
    {
        $subject = self::asObject($r['credentialSubject'] ?? null);
        $g = CitizenKeyChain::grantedAtSeconds(self::asObject($subject['consent'] ?? null)['grantedAt'] ?? null);
        $saltPresent = array_key_exists('citizenKeySalt', $bundle);
        $saltField = $bundle['citizenKeySalt'] ?? null;
        $now = $this->nowSeconds();
        $target = $this->registryToRead();
        $known = self::REGISTRY_BY_CHAIN[$this->chainId()] ?? null;
        $keyOfRegistry = $target['ok'] && $known !== null && strtolower($target['address']) === strtolower($known);
        $out = [
            'state' => CitizenKeyChain::chainState($saltField, $saltPresent, null, false, $g),
            'fp' => '',
            'record' => null,
            'G' => $g,
            'now' => $now,
            'keyOfRegistry' => $keyOfRegistry,
        ];
        $salt = CitizenKeyChain::parseSalt($saltField, $saltPresent);
        if ($salt['status'] !== 'ok') {
            return $out;
        }
        try {
            $fp = CitizenKeyChain::fingerprint(self::asString($subject['id'] ?? null), $coseHex, $salt['salt']);
        } catch (Throwable) {
            return ['state' => 'unreadable'] + $out;
        }
        if (!$target['ok']) {
            return ['state' => 'unreachable', 'fp' => $fp] + $out;
        }
        $call = $this->rpc($this->rpcUrl(), 'eth_call', [['to' => $target['address'], 'data' => CitizenKeyChain::keyOfCalldata($fp)], 'latest']);
        $record = $call['ok'] ? CitizenKeyChain::decodeKeyRecord($call['result']) : null;
        return [
            'state' => CitizenKeyChain::chainState($saltField, $saltPresent, $record, !$call['ok'], $g, $now),
            'fp' => $fp,
            'record' => $record,
        ] + $out;
    }

    /**
     * The company key that verified proof[0], on the company's domain at the instant it signed: companyKeyOf for its
     * window, getCompanyDid for the key the company holds today (issuer.js resolveIssuerAuthenticity). The domain and
     * the key come from the RESOLVED issuer document, never from the bundle.
     *
     * @param array<array-key,mixed> $r
     * @param array{anchoredAt:?int, issuerDatedOnly:bool} $clock
     * @param list<string> $notes
     * @return array{status:string, reading?:array<string,mixed>}
     */
    private function verifyCompanyKeyRegistry(array $r, array $clock, string $companySignature, array &$notes): array
    {
        $signer = $this->signer;
        if ($companySignature !== 'pass' || $signer === null) {
            $notes[] = 'companyKeyRegistry skipped: no company key verified the signature, so there is no key to look up on chain.';
            return ['status' => 'skipped'];
        }
        $keyId = '0x' . hash('sha256', $signer['key']);
        $signedAt = self::secondsOf(self::signingInstantOf($r));
        $domain = CompanyKey::domainFromDoc($signer['doc']);
        $base = ['keyId' => $keyId, 'signedAt' => $signedAt, 'boundedBy' => $clock['anchoredAt'], 'notYetEffective' => false];
        if ($domain === null) {
            $notes[] = 'companyKeyRegistry UNAVAILABLE: the issuer DID document names no company domain (alsoKnownAs), so the '
                . 'company key cannot be looked up on chain.';
            return ['status' => 'unavailable', 'reading' => ['verdict' => 'no-domain', 'domain' => '', 'domainHash' => ''] + $base];
        }
        $domainHash = CompanyKey::domainHashOf($domain);
        $target = $this->registryToRead();
        if (!$target['ok']) {
            $notes[] = "companyKeyRegistry UNAVAILABLE: {$target['reason']}";
            return ['status' => 'unavailable'];
        }
        $rpcUrl = $this->rpcUrl();
        $keyCall = $this->rpc($rpcUrl, 'eth_call', [['to' => $target['address'], 'data' => CompanyKey::companyKeyOfCalldata($domainHash, $keyId)], 'latest']);
        $didCall = $this->rpc($rpcUrl, 'eth_call', [['to' => $target['address'], 'data' => CompanyKey::getCompanyDidCalldata($domainHash)], 'latest']);
        $record = $keyCall['ok'] ? CitizenKeyChain::decodeKeyRecord($keyCall['result']) : null;
        if ($record === null) {
            $why = $keyCall['ok'] ? 'the answer does not decode' : $keyCall['reason'];
            $notes[] = "companyKeyRegistry UNAVAILABLE: the company key's window on {$domain} could not be read ({$why}). This is NOT a refusal.";
            return ['status' => 'unavailable', 'reading' => ['verdict' => 'unreachable', 'domain' => $domain, 'domainHash' => $domainHash] + $base];
        }
        $pit = CompanyKey::verdict($record, $signedAt, $clock);
        $did = $didCall['ok'] ? CompanyKey::decodeCompanyDid($didCall['result']) : null;
        $reading = ['verdict' => $pit['verdict'], 'domain' => $domain, 'domainHash' => $domainHash] + $base;
        $reading['notYetEffective'] = $pit['notYetEffective'];
        if ($pit['window'] !== null) {
            $reading['window'] = $pit['window'];
        }
        if ($did !== null) {
            $reading['currentKeyId'] = $did['keyId'];
            $reading['rotated'] = $pit['verdict'] === 'authentic' && strtolower($did['keyId']) !== $keyId;
        }
        $notes[] = CompanyKey::note($reading);
        return ['status' => CompanyKey::statusOf($pit['verdict']), 'reading' => $reading];
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

        $resolved = $this->resolveCompanyKey($issuer, $vm);
        if ($resolved['kind'] !== 'key') {
            return self::companyKeyRefused($resolved['kind'], $issuer, $vm, $notes);
        }
        ['key' => $key, 'vmId' => $vmId] = $resolved;

        if (Signature::verifyEd25519($key, $canonical, $signature)) {
            $this->signer = ['key' => $key, 'doc' => $resolved['doc']];
            $notes[] = "Company signature verified against issuer DID {$issuer} (key {$vmId}).";
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

        $notes[] = "Company signature check FAILED against issuer DID {$issuer} (key {$vmId}): the canonical receipt body does not "
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
        $resolved = $this->resolveCompanyKey($issuer, $vm);
        if ($resolved['kind'] !== 'key') {
            return self::companyKeyRefused($resolved['kind'], $issuer, $vm, $notes);
        }
        ['key' => $key, 'vmId' => $vmId] = $resolved;
        if (Signature::verifyEd25519($key, $canonical, $signature)) {
            $this->signer = ['key' => $key, 'doc' => $resolved['doc']];
            $notes[] = "Company signature over the signed offer verified against issuer DID {$issuer} (key {$vmId}).";
            return 'pass';
        }
        $notes[] = "Company signature check FAILED against issuer DID {$issuer} (key {$vmId}): the signed offer does not match the "
            . 'signature. The offer was altered after signing or the wrong key was resolved.';
        return 'fail';
    }

    /**
     * The WebAuthn signature over authenticatorData || sha256(clientDataJSON), and clientDataJSON's own challenge.
     *
     * @param array<array-key,mixed> $r
     * @param list<string> $notes
     * @return array{status:string, vm:string, challenge:string, standing:string, coseHex?:string}
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
        if (
            !self::isPlainObject($doc) || ($doc['id'] ?? null) !== $citizenDid
            || !isset($doc['verificationMethod']) || !is_array($doc['verificationMethod']) || !array_is_list($doc['verificationMethod'])
        ) {
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
            'coseHex' => $coseHex,
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
     * Fetch the IPFS document from the gateways in order and compare sha256(JCS(preimage)) with `$disclosureHash`.
     * `$basis` non-null = the v2 preimage. Only bytes that re-encode to the CID are the document: a hash mismatch on
     * them fails, and anything a gateway sends that is not the CID's file moves to the next gateway.
     *
     * @param list<string> $notes
     */
    private function compareDisclosureCopy(string $cid, string $disclosureHash, mixed $basis, array &$notes): string
    {
        $gateways = $this->ipfsGateways();
        if ($gateways === []) {
            $notes[] = "disclosureCopy UNAVAILABLE: no IPFS gateway is configured to fetch CID {$cid}. This is NOT a hash mismatch.";
            return 'unavailable';
        }
        $label = $basis !== null ? ' (disclosureVersion 2: {disclosure, legalBasis})' : '';
        $judge = function (string $bytes) use ($basis, $disclosureHash): array {
            // A UTF-8 decoder drops a leading byte order mark, as the TS SDK's does.
            $text = str_starts_with($bytes, "\xEF\xBB\xBF") ? substr($bytes, 3) : $bytes;
            $body = preg_match('//u', $text) === 1 ? self::decodeJson($text) : null;
            if ($body === null) {
                return ['status' => 'fail', 'detail' => 'is not a JSON document, so it carries no disclosure to compare'];
            }
            // The pinned IPFS body wraps the disclosure map under `disclosure` and carries NO legalBasis today
            // (IpfsDocumentService::canonicalPayload). If a body ever carries one, it must agree with the signed one.
            $bodyObject = self::asObject($body['value']);
            $disclosure = self::asObject($bodyObject['disclosure'] ?? null);
            try {
                if ($basis !== null) {
                    if (
                        array_key_exists('legalBasis', $bodyObject)
                        && $this->canonicalizer->encode($bodyObject['legalBasis']) !== $this->canonicalizer->encode($basis)
                    ) {
                        return ['status' => 'fail', 'detail' => 'carries a legalBasis that differs from the signed one'];
                    }
                    $computed = $this->disclosureHashOf(['disclosure' => $disclosure, 'legalBasis' => $basis]);
                } else {
                    $computed = $this->disclosureHashOf($disclosure);
                }
            } catch (Throwable $e) {
                return [
                    'status' => 'fail',
                    'detail' => "or the legalBasis cannot be canonicalized ({$e->getMessage()}), so the copy cannot be checked",
                ];
            }
            return $computed === $disclosureHash
                ? ['status' => 'pass', 'detail' => "matches the signed disclosureHash {$disclosureHash}"]
                : ['status' => 'fail', 'detail' => "hashes to {$computed}, not the signed disclosureHash {$disclosureHash}"];
        };
        $read = Ipfs::readPinnedFile(
            $cid,
            $gateways,
            $this->pinnedGet(...),
            $judge,
            static fn (array $verdict): bool => $verdict['status'] === 'pass',
        );
        if (!$read['delivered']) {
            $tried = implode('; ', array_map(static fn (array $a): string => "{$a['gateway']} ({$a['failure']})", $read['attempts']));
            $notes[] = "disclosureCopy UNAVAILABLE: no IPFS gateway delivered the file of CID {$cid}: {$tried}. This is NOT a hash mismatch.";
            return 'unavailable';
        }
        $this->disclosureGateway = $read['gateway'];
        if ($read['verdict']['status'] === 'pass') {
            $notes[] = "Disclosure copy verified{$label}: the IPFS document served by {$read['gateway']} {$read['verdict']['detail']}.";
            return 'pass';
        }
        $notes[] = "disclosureCopy FAILED{$label}: the IPFS document of CID {$cid}, served by {$read['gateway']} and re-encoding to that "
            . "CID, {$read['verdict']['detail']}.";
        return 'fail';
    }

    /** "0x" + sha256(JCS(preimage)): the disclosureHash formula the app seals (ConsentRequestService). */
    private function disclosureHashOf(mixed $preimage): string
    {
        return '0x' . hash('sha256', $this->canonicalizer->encode($preimage));
    }

    /**
     * OPT-IN (rpcUrl): is the document CID anchored? One eth_call `rootOf(keccak256(cid))`: lane 3 (a consent
     * document, untagged) passes, an empty record is not yet anchored (unavailable), a record in another lane or tagged fails.
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
        $target = $this->registryToRead();
        if (!$target['ok']) {
            $notes[] = "documentAnchor UNAVAILABLE: {$target['reason']} Nothing was read on chain.";
            return 'unavailable';
        }

        $selector = substr(Keccak::hashHex('rootOf(bytes32)'), 0, 10);
        $call = $this->rpc($rpcUrl, 'eth_call', [['to' => $target['address'], 'data' => $selector . self::stripHex(Keccak::hashHex($cid))], 'latest']);
        $rec = $call['ok'] ? self::decodeRootRecord($call['result']) : null;
        if ($rec === null) {
            $reason = $call['ok'] ? 'the registry answered rootOf with an unreadable record' : $call['reason'];
            $notes[] = "documentAnchor UNAVAILABLE: {$reason}. This is NOT a missing anchor.";
            return 'unavailable';
        }
        // No record is not a contradiction: a document is published first and anchored asynchronously.
        if ($rec['lane'] === 0) {
            $notes[] = "documentAnchor UNAVAILABLE: CID {$cid} is not anchored yet on chainId {$chainId}. "
                . 'A document is anchored after it is published, so an absent record is not a contradiction.';
            return 'unavailable';
        }
        if ($rec['lane'] !== self::DOCUMENT_LANE || preg_match('/^0x0{64}$/', $rec['tag']) !== 1) {
            $as = $rec['lane'] === self::DOCUMENT_LANE
                ? 'a tagged document, which a consent document never is'
                : self::ROOT_LANES[$rec['lane']] ?? "a root of lane {$rec['lane']}";
            $notes[] = "documentAnchor FAILED: keccak256 of CID {$cid} is anchored on chainId {$chainId} as {$as}, not as a "
                . 'consent document.';
            return 'fail';
        }
        $this->documentRecord = ['anchoredAt' => $rec['anchoredAt'], 'anchoredBlock' => $rec['anchoredBlock']];
        $notes[] = "Document anchor FOUND on chainId {$chainId}: CID {$cid} was anchored at {$rec['anchoredAt']} in block "
            . "{$rec['anchoredBlock']}. This proves the DOCUMENT existed, NOT that any consent was given.";
        return 'pass';
    }

    /**
     * OPT-IN (rpcUrl): eth_call registry(consentRef) for each ref. The record is tied to this receipt only through the
     * Merkle root, which is trusted only once a signature binds it: `$untrusted` is the note when none does. A
     * withdrawn cell (state 2) is never a pass: the consent is no longer in force, however authentic.
     * TRAP: a bundle's ref list is signed by no one, so a file can drop a withdrawn ref. Every accepted cell the receipt
     * lists counts (`cells`), and one with no ref is unreadable: a floor, since the items are not proven exhaustive
     * either. With `countAnchoredRefs`, the AnchorBatch log of the trusted root says how many refs its anchor wrote.
     *
     * @param list<string> $refs
     * @param array{cells:int, countAnchoredRefs:bool} $expected
     * @param list<string> $notes
     */
    private function verifyConsentAnchor(array $refs, string $merkleRoot, ?string $untrusted, array $expected, array &$notes): string
    {
        $rpcUrl = $this->rpcUrl();
        if ($rpcUrl === '') {
            $notes[] = "consentAnchor skipped: pass an rpcUrl to read the consent's on-chain record.";
            return 'skipped';
        }
        if ($refs === []) {
            $notes[] = 'consentAnchor skipped: the bundle carries no consentRef to read on chain.';
            return 'skipped';
        }
        if ($untrusted !== null) {
            $notes[] = $untrusted;
            return 'skipped';
        }
        $chainId = $this->chainId();
        $target = $this->registryToRead();
        if (!$target['ok']) {
            $notes[] = "consentAnchor UNAVAILABLE: {$target['reason']}";
            return 'unavailable';
        }

        $root = strtolower(self::stripHex($merkleRoot));
        $selector = substr(Keccak::hashHex('registry(bytes32)'), 0, 10);
        $records = [];
        $cells = max(count($refs), $expected['cells']);
        $active = 0;
        $withdrawn = 0;
        $pending = 0;
        $unread = $cells - count($refs);
        $foreign = [];
        foreach ($refs as $ref) {
            $call = $this->rpc($rpcUrl, 'eth_call', [['to' => $target['address'], 'data' => $selector . self::stripHex($ref)], 'latest']);
            $rec = $call['ok'] ? self::decodeAnchorRecord($call['result']) : null;
            $revoked = $rec !== null && ($rec['state'] === 2 || $rec['revokedAt'] > 0);
            // An anchoredAt with neither state Active nor Revoked is a garbage record, not a pending anchor.
            if ($rec === null || ($rec['anchoredAt'] > 0 && !$revoked && $rec['state'] !== 1)) {
                $unread++;
            } elseif ($rec['anchoredAt'] === 0) {
                $pending++;
            } elseif (strtolower(self::stripHex($rec['merkleRoot'])) !== $root) {
                $foreign[] = $ref;
            } else {
                $revoked ? $withdrawn++ : $active++;
                $records[] = [
                    'consentRef' => $ref,
                    'state' => $revoked ? 'withdrawn' : 'active',
                    'anchoredAt' => $rec['anchoredAt'],
                    'revokedAt' => $rec['revokedAt'],
                    'anchoredBlock' => $rec['anchoredBlock'],
                ];
            }
        }
        if ($records !== []) {
            $this->consentRecords = $records;
            $times = array_column($records, 'anchoredAt');
            $this->consentTiming = ['earliest' => min($times), 'latest' => max($times)];
        }

        if ($foreign !== []) {
            $notes[] = "consentAnchor FAILED: the chain records consentRef {$foreign[0]} under a different Merkle root than this "
                . "bundle's, so the bundle's chain reference does not belong to this receipt.";
            return 'fail';
        }
        // How many refs the consent's own anchor wrote: the bundle's ref list and its items are signed by no one, the
        // AnchorBatch log of the trusted root is (chain.js readAnchoredRefCount). Unknown is never in force.
        $batchRefs = null;
        $countUnknown = false;
        if ($expected['countAnchoredRefs'] && $records !== []) {
            $batchRefs = $this->readAnchorBatchCount($rpcUrl, $target['address'], '0x' . $root, array_column($records, 'anchoredBlock'));
            if ($batchRefs === null) {
                $countUnknown = true;
            } else {
                // Only a ref the chain records under the trusted root fills a slot. TRAP: never subtract what the bundle
                // names: it can pad its list with refs that were never anchored.
                $cells = max($cells, $batchRefs);
                $unread = max(0, $cells - $active - $withdrawn - $pending);
            }
        }
        $counts = "{$cells} cell(s): {$active} active, {$withdrawn} withdrawn, {$pending} not anchored yet, {$unread} unreadable";
        $this->consentCounts = ['cells' => $cells, 'active' => $active, 'withdrawn' => $withdrawn, 'pending' => $pending, 'unreadable' => $unread];
        if ($batchRefs !== null) {
            $this->consentCounts['anchorBatchRefs'] = $batchRefs;
        }
        $blocks = implode(', ', array_values(array_unique(array_column($records, 'anchoredBlock'))));
        if ($withdrawn > 0) {
            $notes[] = "consentAnchor WITHDRAWN on chainId {$chainId} ({$counts}). The chain records the consent under "
                . "this bundle's Merkle root, anchored in block {$blocks}, and records its withdrawal: it is authentic, but "
                . 'it is no longer in force. This is NOT a forgery.';
            return 'withdrawn';
        }
        if ($active !== $cells) {
            $named = count($refs);
            $unnamed = '';
            if ($batchRefs !== null && $batchRefs > $named) {
                $unnamed = " The consent's anchor wrote {$batchRefs} refs and the bundle names {$named}: an unnamed ref counts as unreadable.";
            } elseif ($cells > $named) {
                $unnamed = " The bundle names a consentRef for {$named} of the receipt's {$cells} accepted cells, and "
                    . 'no one signs that list: an unnamed cell counts as unreadable.';
            }
            $notes[] = "consentAnchor UNAVAILABLE on chainId {$chainId} ({$counts}).{$unnamed} Anchoring is asynchronous and an "
                . 'RPC can fail: this is NOT a contradiction.';
            return 'unavailable';
        }
        if ($countUnknown) {
            $notes[] = "consentAnchor UNAVAILABLE on chainId {$chainId} ({$counts}): how many refs the consent's anchor wrote "
                . 'could not be read (no single AnchorBatch log of this root at the one block its refs sit in), so the consent '
                . 'is not shown in force. This is NOT a contradiction.';
            return 'unavailable';
        }
        $notes[] = "Consent anchor FOUND on chainId {$chainId} under this bundle's Merkle root ({$counts}), anchored in "
            . "block {$blocks}.";
        return 'pass';
    }

    /**
     * The refCount of the ONE AnchorBatch log for `$root` at the ONE block every matching ref sits in, read with a
     * single-block eth_getLogs on the registry read (AnchorBatch). Null (unknown) when the refs sit at more than one
     * block, on a read failure, or when anything but exactly one log of that root, from that address, at that block
     * answers.
     *
     * @param list<int> $blocks
     */
    private function readAnchorBatchCount(string $rpcUrl, string $address, string $root, array $blocks): ?int
    {
        $tag = AnchorBatch::blockTag($blocks);
        if ($tag === null) {
            return null;
        }
        $call = $this->rpc($rpcUrl, 'eth_getLogs', [AnchorBatch::filter($address, $root, $tag)]);
        return $call['ok'] ? AnchorBatch::refCount($call['result'], $address, $root, $blocks[0]) : null;
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

    /**
     * The registry the anchor checks read: the explicit registryAddress, else the chain's known one. A bundle that
     * names another registry or chain is refused, with no RPC: what it names can narrow the read, never redirect it.
     *
     * @return array{ok:true, address:string}|array{ok:false, reason:string}
     */
    private function registryToRead(): array
    {
        $chainId = $this->chainId();
        $explicit = $this->opts['registryAddress'] ?? null;
        $address = is_string($explicit) && $explicit !== '' ? $explicit : (self::REGISTRY_BY_CHAIN[$chainId] ?? null);
        if ($address === null) {
            return ['ok' => false, 'reason' => "no known registry for this chain (chainId {$chainId}): pass its registryAddress."];
        }
        $named = $this->namedRegistry;
        if (
            $named !== null
            && (strtolower($named['registry']) !== strtolower($address) || ($named['chainId'] !== '' && $named['chainId'] !== (string) $chainId))
        ) {
            $on = $named['chainId'] !== '' ? " on chainId {$named['chainId']}" : '';
            $this->registryRefused = true;
            return [
                'ok' => false,
                'reason' => "unknown registry: the bundle names the registry {$named['registry']}{$on}, which is not a known "
                    . "registry for chainId {$chainId}. Nothing was read on chain.",
            ];
        }
        return ['ok' => true, 'address' => $address];
    }

    private function intOpt(string $name): ?int
    {
        $v = $this->opts[$name] ?? null;
        return is_int($v) ? $v : null;
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

    /**
     * The key that checks proof[0] (companyKeyMethod). A document that did not resolve is "unresolved" (unavailable); a
     * resolved one with no such method, or whose method carries no Ed25519 multikey, is "unlisted" (fail).
     *
     * @return array{kind:'unresolved'}|array{kind:'unlisted'}|array{kind:'key', key:string, vmId:string, doc:array<array-key,mixed>}
     */
    private function resolveCompanyKey(string $issuer, string $vmId): array
    {
        $doc = $this->resolveDid($issuer);
        if ($doc === null || !self::isPlainObject($doc)) {
            return ['kind' => 'unresolved'];
        }
        $vm = self::companyKeyMethod($doc, $issuer, $vmId);
        if ($vm === null) {
            return ['kind' => 'unlisted'];
        }
        try {
            return ['kind' => 'key', 'key' => Multibase::decodeEd25519PublicKey($vm['publicKeyMultibase']), 'vmId' => $vm['id'], 'doc' => $doc];
        } catch (Throwable) {
            return ['kind' => 'unlisted'];
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

    /**
     * The gateways asked for an IPFS document, in order: the one the caller names (ipfsGateway, a string or a function
     * of the CID) first, then ipfsGateways (strings), the default list when that option is not an array. An empty
     * ipfsGateways with no ipfsGateway asks none.
     *
     * @return list<mixed>
     */
    private function ipfsGateways(): array
    {
        $named = $this->opts['ipfsGateway'] ?? null;
        $list = $this->opts['ipfsGateways'] ?? null;
        $first = $named !== null && Ipfs::isGateway($named) ? [$named] : [];
        $rest = is_array($list) ? array_values(array_filter($list, 'is_string')) : Ipfs::DEFAULT_GATEWAYS;
        return [...$first, ...$rest];
    }

    /**
     * GET one gateway's copy of a pinned file. An injected httpGet answers as it does for every GET (a failure is null,
     * with no status); curl sends no Accept header, follows redirects as a browser fetch does, and stops reading one
     * byte past MAX_SINGLE_BLOCK_BYTES, so a hostile gateway cannot make the verifier buffer more.
     *
     * @return array{body:?string, status:?int}
     */
    private function pinnedGet(string $url): array
    {
        if (is_callable($this->opts['httpGet'] ?? null) || $url === '') {
            return $this->fetch($url);
        }
        $cap = Ipfs::MAX_SINGLE_BLOCK_BYTES + 1;
        $body = '';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS => self::positiveInt($this->opts['timeoutMs'] ?? null, self::DEFAULT_TIMEOUT_MS),
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $cap): int {
                $body .= substr($chunk, 0, max(0, $cap - strlen($body)));
                // Returning fewer bytes than were handed over aborts the transfer.
                return strlen($body) >= $cap ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($status !== 0 && ($status < 200 || $status > 299)) {
            return ['body' => null, 'status' => $status];
        }
        if (strlen($body) >= $cap) {
            return ['body' => $body, 'status' => null];
        }
        return $ok === true && $status !== 0 ? ['body' => $body, 'status' => null] : ['body' => null, 'status' => null];
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

    /**
     * @param array<array-key,mixed> $r
     * @return 'company_attested'|'company_documented'|'citizen'
     */
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
     * The person's WebAuthn assertion is proof[1], as ConsentReceipt::build writes it, never another position.
     *
     * @param list<mixed> $proofs
     * @return array<array-key,mixed>|null
     */
    private static function findWebAuthnProof(array $proofs): ?array
    {
        $obj = self::asObject($proofs[1] ?? null);
        return self::asString($obj['type'] ?? null) === 'WebAuthnAssertion' ? $obj : null;
    }

    /**
     * Exactly `$keys`, every value a string; the members as an array, or null.
     *
     * @param list<string> $keys
     * @return array<array-key,string>|null
     */
    private static function exactStringMembers(mixed $o, array $keys): ?array
    {
        if (!self::isPlainObject($o)) {
            return null;
        }
        $members = self::asObject($o);
        if (count($members) !== count($keys)) {
            return null;
        }
        foreach ($keys as $k) {
            if (!array_key_exists($k, $members) || !is_string($members[$k])) {
                return null;
            }
        }
        /** @var array<array-key,string> $members */
        return $members;
    }

    private static function controlledBy(string $vm, mixed $did): bool
    {
        return is_string($did) && $did !== '' && str_starts_with($vm, $did . '#');
    }

    /**
     * proof[0] as the issuer writes it (verify-core.js issuerProofShaped): the company's eddsa-jcs-2022 proof.
     *
     * @return array<array-key,string>|null
     */
    private static function issuerProofShaped(mixed $proof, mixed $issuer): ?array
    {
        $p = self::exactStringMembers($proof, self::ISSUER_PROOF_KEYS);
        return $p !== null
            && $p['type'] === 'DataIntegrityProof'
            && $p['cryptosuite'] === 'eddsa-jcs-2022'
            && $p['proofPurpose'] === 'assertionMethod'
            && self::controlledBy($p['verificationMethod'], $issuer) ? $p : null;
    }

    /**
     * proof[1] as ConsentReceipt::build writes it (verify-core.js citizenProofShaped): the person's WebAuthn assertion.
     *
     * @return array<array-key,string>|null
     */
    private static function citizenProofShaped(mixed $proof, mixed $subject): ?array
    {
        $p = self::exactStringMembers($proof, self::CITIZEN_PROOF_KEYS);
        return $p !== null
            && $p['type'] === 'WebAuthnAssertion'
            && self::controlledBy($p['verificationMethod'], $subject) ? $p : null;
    }

    /**
     * A passkey receipt carries exactly the company's proof then the person's, and the members the issuer copies from
     * the signed offer equal it (verify-core.js passkeyProofsShaped + proofMembersPinned). An offer without createdAt
     * pins no `created`, as offerWindowOf skips it.
     *
     * @param array<array-key,mixed> $r
     * @param array<array-key,mixed> $offer
     */
    private static function passkeyProofFailure(array $r, array $offer): ?string
    {
        $proof = self::asArray($r['proof'] ?? null);
        $subject = self::asObject($r['credentialSubject'] ?? null)['id'] ?? null;
        $issuerProof = self::issuerProofShaped($proof[0] ?? null, $r['issuer'] ?? null);
        $citizenProof = self::citizenProofShaped($proof[1] ?? null, $subject);
        if (count($proof) !== 2 || $issuerProof === null || $citizenProof === null) {
            return "the proof array is not the company's DataIntegrityProof then the person's WebAuthnAssertion, each with exactly the members the issuer writes";
        }
        $createdAt = $offer['createdAt'] ?? null;
        if (is_string($createdAt) && $issuerProof['created'] !== $createdAt) {
            return "proof[0].created differs from the signed offer's createdAt";
        }
        if ($citizenProof['nonce'] !== ($offer['nonce'] ?? null)) {
            return "proof[1].nonce differs from the signed offer's nonce";
        }
        return null;
    }

    /**
     * A recorded receipt carries exactly one company proof, and a v2 to v4 one was created at its recordedAt.
     *
     * @param array<array-key,mixed> $r
     */
    private static function recordedProofFailure(array $r): ?string
    {
        $proof = self::asArray($r['proof'] ?? null);
        $issuerProof = self::issuerProofShaped($proof[0] ?? null, $r['issuer'] ?? null);
        if (count($proof) !== 1 || $issuerProof === null) {
            return 'the proof array is not exactly one company DataIntegrityProof with the members the issuer writes';
        }
        // TRAP: a v1 receipt (no receiptVersion) pins nothing until production is measured to hold created === grantedAt.
        if (!array_key_exists('receiptVersion', $r)) {
            return null;
        }
        $consent = self::asObject(self::asObject($r['credentialSubject'] ?? null)['consent'] ?? null);
        $recordedAt = $consent['recordedAt'] ?? null;
        if (!is_string($recordedAt) || $issuerProof['created'] !== $recordedAt) {
            return "proof[0].created differs from the consent's recordedAt";
        }
        return null;
    }

    /**
     * The note and verdict when no key checks proof[0]: an unresolved document is inconclusive, an unlisted key fails.
     *
     * @param 'unresolved'|'unlisted' $kind
     * @param list<string> $notes
     */
    private static function companyKeyRefused(string $kind, string $issuer, string $vm, array &$notes): string
    {
        if ($kind === 'unresolved') {
            $notes[] = "Company signature UNVERIFIABLE: could not resolve the issuer DID {$issuer} (key {$vm}) to an Ed25519 key "
                . '(network/resolution failure or key not found). This is INCONCLUSIVE, NOT a signature mismatch.';
            return 'unavailable';
        }
        $notes[] = "Company signature check FAILED: the issuer DID document of {$issuer} lists no Ed25519 key under assertionMethod "
            . "for proof[0] (key {$vm}), so no key of the company may check this signature.";
        return 'fail';
    }

    /**
     * The method that checks proof[0] (did.js issuerKeyFromDoc): the one whose id is `$vmId`, an id under the issuer
     * (issuer + '#' + fragment), controlled by the issuer, referenced under the document's `assertionMethod` (full id,
     * or '#fragment', which resolves only as issuer + '#fragment') and carrying a multibase key. A
     * fragment of the issuer naming no method falls back to the document's SOLE assertionMethod method. Never the first
     * method: the document also lists the company's own `#recovery-1` key (capabilityInvocation), which Agreely never holds.
     *
     * @param array<array-key,mixed> $doc
     * @return array{id:string, publicKeyMultibase:string}|null
     */
    private static function companyKeyMethod(array $doc, string $issuer, string $vmId): ?array
    {
        // A resolved document is outside input too: an entry that is not an object is skipped, never dereferenced.
        $methods = array_values(array_filter(
            self::asArray($doc['verificationMethod'] ?? null),
            static fn (mixed $m): bool => is_array($m) && self::isPlainObject($m),
        ));
        $assertion = self::asArray($doc['assertionMethod'] ?? null);
        // A method named under another DID never checks proof[0], even one the issuer controls.
        $asserts = static function (array $m) use ($assertion, $issuer): bool {
            $id = $m['id'] ?? null;
            return is_string($id) && $issuer !== '' && str_starts_with($id, $issuer . '#')
                && (in_array($id, $assertion, true) || in_array(substr($id, strlen($issuer)), $assertion, true));
        };
        $named = array_values(array_filter($methods, static fn (array $m): bool => ($m['id'] ?? null) === $vmId));
        $vm = null;
        if (count($named) === 1) {
            $vm = $named[0];
        } elseif ($named === [] && $issuer !== '' && str_starts_with($vmId, $issuer . '#')) {
            $listed = array_values(array_filter($methods, $asserts));
            $vm = count($listed) === 1 ? $listed[0] : null;
        }
        if ($vm === null || ($vm['controller'] ?? null) !== $issuer || !$asserts($vm)) {
            return null;
        }
        $id = $vm['id'] ?? null;
        $multibase = $vm['publicKeyMultibase'] ?? null;
        return is_string($id) && is_string($multibase) ? ['id' => $id, 'publicKeyMultibase' => $multibase] : null;
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
        if (!$this->canonEqual($r['type'] ?? null, self::PASSKEY_RECEIPT_TYPE)) {
            return 'the receipt type is not a passkey ConsentReceipt';
        }
        if (!$this->canonEqual($r['@context'] ?? null, self::PASSKEY_RECEIPT_CONTEXT)) {
            return 'the receipt @context is not the consent receipt context';
        }
        if (!is_string($r['id'] ?? null) || !str_starts_with($r['id'], 'urn:agreely:receipt:')) {
            return 'the receipt id is not an urn:agreely:receipt identifier';
        }
        $consent = self::asObject(self::asObject($r['credentialSubject'] ?? null)['consent'] ?? null);
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
        return self::passkeyProofFailure($r, $offer);
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
     * Decode registry(bytes32): merkleRoot, anchoredAt, revokedAt, state, anchoredBlock. A prefix read: trailing data
     * is ignored, a shorter answer or a word out of its type's range is null, so it reads as unreadable, never as a
     * contradiction.
     *
     * @return array{merkleRoot:string, anchoredAt:int, revokedAt:int, state:int, anchoredBlock:int}|null
     */
    private static function decodeAnchorRecord(mixed $result): ?array
    {
        $h = strtolower(self::stripHex(self::asString($result)));
        if (preg_match('/^[0-9a-f]{320}/', $h) !== 1) {
            return null;
        }
        $word = static fn (int $i): string => substr($h, $i * 64, 64);
        $anchoredAt = self::uint64Word($word(1));
        $revokedAt = self::uint64Word($word(2));
        $anchoredBlock = self::uint64Word($word(4));
        if (preg_match('/^0{62}[0-9a-f]{2}$/', $word(3)) !== 1 || $anchoredAt === null || $revokedAt === null || $anchoredBlock === null) {
            return null;
        }
        return [
            'merkleRoot' => '0x' . $word(0),
            'anchoredAt' => $anchoredAt,
            'revokedAt' => $revokedAt,
            'state' => (int) hexdec(substr($word(3), 62)),
            'anchoredBlock' => $anchoredBlock,
        ];
    }

    /**
     * Decode rootOf(bytes32): tag, anchoredAt, anchoredBlock, lane. Null on anything else, and on a record that
     * contradicts itself (a lane without an anchor time, or an anchor time without a lane).
     *
     * @return array{tag:string, anchoredAt:int, anchoredBlock:int, lane:int}|null
     */
    private static function decodeRootRecord(mixed $result): ?array
    {
        $h = strtolower(self::stripHex(self::asString($result)));
        if (preg_match('/^[0-9a-f]{256}/', $h) !== 1) {
            return null;
        }
        $word = static fn (int $i): string => substr($h, $i * 64, 64);
        $anchoredAt = self::uint64Word($word(1));
        $anchoredBlock = self::uint64Word($word(2));
        if ($anchoredAt === null || $anchoredBlock === null || preg_match('/^0{62}[0-9a-f]{2}$/', $word(3)) !== 1) {
            return null;
        }
        $lane = (int) hexdec(substr($word(3), 62));
        if (($lane === 0) !== ($anchoredAt === 0)) {
            return null;
        }
        return ['tag' => '0x' . $word(0), 'anchoredAt' => $anchoredAt, 'anchoredBlock' => $anchoredBlock, 'lane' => $lane];
    }

    /** A uint64 ABI word as a safe integer, null when it is not one. */
    private static function uint64Word(string $w): ?int
    {
        return preg_match('/^0{48}[0-9a-f]{16}$/', $w) === 1 ? self::safeHexInt(substr($w, 48)) : null;
    }

    /**
     * The registry a bundle names: its issuer's key delegation is bound to one chain and one registry. Null when
     * the bundle names none. The delegation is not verified here, so this is only ever used to refuse a read.
     *
     * @param array<array-key,mixed> $bundle
     * @return array{chainId:string, registry:string}|null
     */
    private static function namedRegistryOf(array $bundle): ?array
    {
        $issuer = self::asObject(self::asObject($bundle['didDocuments'] ?? null)['issuer'] ?? null);
        $body = self::asObject(self::asObject($issuer['delegation'] ?? null)['body'] ?? null);
        $registry = self::asString($body['registry'] ?? null);
        if ($registry === '') {
            return null;
        }
        $chainId = $body['chainId'] ?? null;
        return ['registry' => $registry, 'chainId' => is_string($chainId) || is_int($chainId) ? (string) $chainId : ''];
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

    /**
     * A field the issuer never writes on a passkey receipt (ConsentReceipt::build): the file is not one Agreely issued,
     * so it is unreadable, never a tamper, as the web verifier's bundleShapeOf reads it.
     *
     * @param array<array-key,mixed> $r
     */
    private static function passkeyFieldOutsideFormat(array $r): ?string
    {
        foreach (array_keys($r) as $k) {
            if (!in_array((string) $k, self::PASSKEY_RECEIPT_KEYS, true)) {
                return "the passkey receipt carries a top-level field the issuer never writes ({$k})";
            }
        }
        $subject = self::asObject($r['credentialSubject'] ?? null);
        foreach (array_keys($subject) as $k) {
            if ($k !== 'id' && $k !== 'consent') {
                return "the passkey receipt's credentialSubject carries a field the issuer never writes ({$k})";
            }
        }
        foreach (array_keys(self::asObject($subject['consent'] ?? null)) as $k) {
            if (!in_array((string) $k, self::ALLOWED_CONSENT_KEYS, true)) {
                return "the passkey receipt's consent carries a field the issuer never writes ({$k})";
            }
        }
        return null;
    }

    /**
     * The instant the company key signed (issuer.js signingInstantOf): a recorded receipt of version 2 to 4 was signed
     * when the organisation recorded it, a version 1 recorded receipt and a passkey receipt at grantedAt. A version 2
     * to 4 without recordedAt yields null.
     *
     * @param array<array-key,mixed> $r
     */
    private static function signingInstantOf(array $r): ?string
    {
        $consent = self::asObject(self::asObject($r['credentialSubject'] ?? null)['consent'] ?? null);
        $at = in_array($r['receiptVersion'] ?? null, ['2', '3', '4'], true) ? $consent['recordedAt'] ?? null : $consent['grantedAt'] ?? null;
        return is_string($at) ? $at : null;
    }

    /** An instant (parseInstant) as whole unix seconds, floored, or null. */
    private static function secondsOf(?string $instant): ?int
    {
        $ms = self::parseInstant($instant);
        return $ms === null ? null : intdiv($ms - (($ms % 1000) + 1000) % 1000, 1000);
    }

    private function rpcUrl(): string
    {
        return self::asString($this->opts['rpcUrl'] ?? null);
    }

    /** The verifier's clock in unix seconds: the `now` option (a callable returning unix seconds), else time(). */
    private function nowSeconds(): int
    {
        $clock = $this->opts['now'] ?? null;
        $t = is_callable($clock) ? $clock() : null;
        if (is_int($t)) {
            return $t;
        }
        return is_float($t) && is_finite($t) ? (int) floor($t) : time();
    }

    /**
     * Accepted cells the receipt lists: one consentRef each.
     *
     * @param array<array-key,mixed> $r
     */
    private static function acceptedCellCount(array $r): int
    {
        return count(self::asArray(self::asObject(self::asObject($r['credentialSubject'] ?? null)['consent'] ?? null)['items'] ?? null));
    }

    /**
     * What the consent anchor read says about the consent being in force. Only a complete read says "active".
     *
     * @return array{withdrawal:string, withdrawnAt:?int}
     */
    private function withdrawalOf(string $consentAnchor): array
    {
        if ($consentAnchor === 'pass') {
            return ['withdrawal' => 'active', 'withdrawnAt' => null];
        }
        if ($consentAnchor !== 'withdrawn') {
            return ['withdrawal' => 'not_checked', 'withdrawnAt' => null];
        }
        $latest = 0;
        foreach ($this->consentRecords ?? [] as $rec) {
            if ($rec['state'] === 'withdrawn' && $rec['revokedAt'] > $latest) {
                $latest = $rec['revokedAt'];
            }
        }
        return ['withdrawal' => 'withdrawn', 'withdrawnAt' => $latest > 0 ? $latest : null];
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
            withdrawal: 'not_checked',
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
