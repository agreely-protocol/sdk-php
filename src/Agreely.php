<?php

declare(strict_types=1);

namespace Agreely\Sdk;

use Agreely\Sdk\Degrade\DegradePolicy;
use Agreely\Sdk\Errors\AgreelyBillingInactiveError;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyUnavailableError;
use Agreely\Sdk\Http\CurlHttpClient;
use Agreely\Sdk\Http\HttpClient;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\Resources\Catalog;
use Agreely\Sdk\Resources\ConsentRequests;
use Agreely\Sdk\Resources\ManualConsents;
use Agreely\Sdk\Resources\Relationships;
use Agreely\Sdk\Types\BatchCheckItem;
use Agreely\Sdk\Types\BatchDecision;
use Agreely\Sdk\Types\CheckFieldsResult;
use Agreely\Sdk\Types\CheckResult;
use Agreely\Sdk\Types\Identity;
use Agreely\Sdk\Types\Wire;
use Agreely\Sdk\Verify\ReceiptVerification;
use Agreely\Sdk\Verify\ReceiptVerifier;

/**
 * The Agreely client: a thin, near-stateless gate over the /v1 API. It holds an
 * api key, a base URL, an HTTP client, a timeout, and the degrade policy — NO
 * database, NO ref tables, and NO allow-cache (caching an allow while a revoke
 * lands mid-window is a stale-allow correctness failure, spec §16). Every check()
 * is a fresh authoritative call.
 *
 * Ported 1:1 from the @agreely/sdk TypeScript reference. Break-glass (TS gate 3)
 * is intentionally omitted in PHP v1 — it needs a shared store in PHP's
 * request-scoped model (see README + DegradeContext).
 */
final class Agreely
{
    private const DEFAULT_BASE_URL = 'https://api.agreely.ca';
    private const DEFAULT_TIMEOUT_MS = 800;

    /**
     * SERVER LIMITS, mirrored here so the SDK can fail fast with a clear client-side
     * error instead of letting you discover them as a 422 / 429 in production.
     *
     * BATCH_CAP: POST /v1/check/batch accepts at most 500 items. Over that the server
     * answers 422 "Batch exceeds the 500-item cap." and decides NOTHING (so a
     * fail-closed caller must treat the whole batch as deny). checkBatch() and
     * checkFields() both refuse over-cap input BEFORE the wire call.
     *
     * RATE_LIMIT_PER_MINUTE: the /v1 tier allows 120 requests per minute PER COMPANY
     * (not per api key), a coarse fixed window. Over it the server answers 429 with a
     * Retry-After header, which this SDK surfaces as AgreelyRateLimitError (and
     * honours on retry when respectRetryAfter is on). It is a deployment default and
     * a self-hosted or negotiated deployment may differ; treat it as the number to
     * design against, not a contract. ONE batch call of 500 cells costs ONE request,
     * which is exactly why checkBatch exists.
     */
    public const BATCH_CAP = 500;
    public const RATE_LIMIT_PER_MINUTE = 120;

    private readonly Transport $transport;
    private readonly DegradePolicy $degrade;
    private readonly ConsentRequests $consentRequests;
    private readonly ManualConsents $manualConsents;
    private readonly Relationships $relationships;
    private readonly Catalog $catalog;
    private readonly string $baseUrl;

    /**
     * Recognised keys: apiKey (string, required), baseUrl (string),
     * timeout (int ms), degradeOnOutage (array, see DegradePolicy),
     * maxDegradeWindow (duration string, e.g. "12h"), httpClient (HttpClient).
     *
     * TIMEOUT: the default is 800ms as a TOTAL budget (connect + transfer + any
     * transient-outage retry). That number is sized for a call inside the SAME
     * datacentre or region as the API. It is NOT a safe budget over the open
     * internet: a cold TLS handshake plus a cross-region round trip can exceed it on
     * a perfectly healthy server.
     *
     * This matters because the SDK FAILS CLOSED: a timeout is treated as an outage,
     * so check() returns false and checkDetailed() throws AgreelyUnavailableError. An
     * under-set timeout therefore does not produce a slow answer, it produces a
     * SPURIOUS DENY, and the person is shown less of their own data than they
     * consented to. If you call api.agreely.ca from outside its region, RAISE IT.
     * A well-tested internet-facing pairing is:
     *
     *     new Agreely(['apiKey' => $key, 'timeout' => 8000, 'maxRetries' => 1]);
     *
     * The default is deliberately left low rather than raised for everyone: this is a
     * synchronous gate on a request path, and an unattended multi-second default would
     * turn an Agreely outage into a multi-second hang on every one of your pages.
     * Choose the budget your deployment can actually pay.
     *
     * @param array<string,mixed> $options
     */
    public function __construct(array $options)
    {
        $apiKey = $options['apiKey'] ?? '';
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new AgreelyConfigError('Agreely requires an apiKey.');
        }

        $httpClient = $options['httpClient'] ?? new CurlHttpClient();
        if (!$httpClient instanceof HttpClient) {
            throw new AgreelyConfigError('options[httpClient] must implement ' . HttpClient::class . '.');
        }

        $baseUrl = isset($options['baseUrl']) && is_string($options['baseUrl'])
            ? $options['baseUrl']
            : self::DEFAULT_BASE_URL;
        $timeout = isset($options['timeout']) && is_int($options['timeout'])
            ? $options['timeout']
            : self::DEFAULT_TIMEOUT_MS;

        $maxRetries = isset($options['maxRetries']) && is_int($options['maxRetries']) ? $options['maxRetries'] : 0;
        $respectRetryAfter = !isset($options['respectRetryAfter']) || $options['respectRetryAfter'] !== false;

        $this->baseUrl = $baseUrl;
        $this->transport = new Transport($baseUrl, $apiKey, $timeout, $httpClient, $maxRetries, $respectRetryAfter);

        // The shared upper bound for the maxOutageWindow.
        $maxDegradeWindowMs = isset($options['maxDegradeWindow']) && is_string($options['maxDegradeWindow'])
            ? Duration::parse($options['maxDegradeWindow'])
            : Duration::DEFAULT_MAX_DEGRADE_WINDOW_MS;

        // Construction validates the degrade config (fail-open without onDegrade
        // throws, an over-cap maxOutageWindow throws).
        $degradeConfig = $options['degradeOnOutage'] ?? null;
        /** @var array<string,mixed>|null $degradeConfig */
        $degradeConfig = is_array($degradeConfig) ? $degradeConfig : null;
        $this->degrade = new DegradePolicy($degradeConfig, $maxDegradeWindowMs);

        $this->consentRequests = new ConsentRequests($this->transport);
        $this->manualConsents = new ManualConsents($this->transport);
        $this->relationships = new Relationships($this->transport);
        $this->catalog = new Catalog($this->transport);
    }

    /** The consent-request resource (issuance, scope 'issue'). */
    public function consentRequests(): ConsentRequests
    {
        return $this->consentRequests;
    }

    /** The manual / offline (company-attested) consent resource (scope 'attest'). */
    public function manualConsents(): ManualConsents
    {
        return $this->manualConsents;
    }

    /** The customer-relationship lifecycle resource (scope 'relationship'). */
    public function relationships(): Relationships
    {
        return $this->relationships;
    }

    /** The catalog resource (discovery, scope 'check' OR 'issue'). */
    public function catalog(): Catalog
    {
        return $this->catalog;
    }

    /** The configured API base URL (client-side; the resolved endpoint in use). */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Identify the presented key against the server (GET /v1/whoami): the REAL,
     * server-verified scopes it carries. Least-disclosure — the wire response is
     * scopes-only (no company id, no key name, no PII). Any valid key reaches it.
     * The returned Identity also echoes the configured baseUrl (client-side). A
     * read; safe to auto-retry on a transient outage.
     */
    public function identity(): Identity
    {
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/whoami',
            idempotentRetry: true,
        ));
        return Identity::fromWire($wire, $this->baseUrl);
    }

    /**
     * Verify a consent receipt OFFLINE-FIRST (the headline). Reports exactly what is
     * PROVED vs merely trusted: a company-attested receipt is offline-sound; a citizen
     * receipt is honestly PARTIAL offline, because its company half signed the OFFER,
     * which the receipt omits for unlinkability. NO server endpoint re-checks that
     * half; it travels only in the separate verification bundle Agreely issues with the
     * receipt, which this verifier does not read. "Offline-first", not fully offline: the
     * signature/assertion checks need the signing key from the DID document (one HTTPS
     * resolution by default, or supply a local `resolver` for an air-gapped verify);
     * IPFS/anchor are the opt-in extra calls. When a DID cannot be resolved the check
     * is "unavailable" (inconclusive), never "fail" (a tamper). Static — no API key.
     *
     * SECURITY: when verifying UNTRUSTED receipts, inject your own `resolver` (or
     * supply DID documents locally) — the default did:web resolver fetches a host
     * taken from the receipt (HTTPS-only; it can never yield a false "verified").
     *
     * @param mixed $receipt a parsed receipt VC (assoc array)
     * @param array<string,mixed> $opts see {@see ReceiptVerifier}
     */
    public static function verifyReceipt(mixed $receipt, array $opts = []): ReceiptVerification
    {
        return (new ReceiptVerifier($opts))->verify($receipt);
    }

    /**
     * Hash PDF bytes to the EXACT `evidence.pdfSha256` form the API expects:
     * "0x" + lowercase sha256 hex. Static; no network.
     */
    public static function hashPdf(string $bytes): string
    {
        return '0x' . hash('sha256', $bytes);
    }

    /** Read a PDF from disk and hash it — see {@see Agreely::hashPdf}. */
    public static function hashPdfFile(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new AgreelyConfigError("hashPdfFile: could not read \"{$path}\".");
        }
        return self::hashPdf($bytes);
    }

    /**
     * The boolean-ergonomic consent gate. ALLOW is the only true. A 200 deny ->
     * false. On an outage, the fail-closed default returns false; the explicit,
     * scoped, audited exception (config + per-call opt) may return true. NEVER
     * throws on an outage — it resolves to a boolean.
     *
     * Send RAW category/purpose — the server normalizes; the SDK never does.
     * Labels may be French OR English, with or without accents (case- and
     * whitespace-insensitive); English resolves only when the company disclosed an
     * English label for that cell, and an ambiguous/undeclared label fails closed.
     *
     * @param array{onOutage?:'allow'|'deny'} $opts
     */
    public function check(string $customerId, string $category, string $purpose, array $opts = []): bool
    {
        try {
            return $this->resolve($customerId, $category, $purpose, $opts)->isAllow();
        } catch (AgreelyUnavailableError | AgreelyBillingInactiveError) {
            // Fail-closed deny. An outage OR a lapsed company subscription (402)
            // must never resolve to an allow. checkDetailed() still THROWS the
            // typed error so a caller can surface "billing lapsed" distinctly.
            return false;
        }
        // Auth / validation / rate-limit / not-found surface as thrown errors.
    }

    /**
     * Batch consent check: evaluate many (customerRef, category, purpose) cells in
     * ONE round-trip instead of N calls to check(). Returns decisions ALIGNED to
     * the submitted items. Fail-closed: any tuple without an active record returns
     * deny/none. Sends category/purpose RAW (the server normalizes; the SDK never
     * does). On an outage throws AgreelyUnavailableError.
     *
     * CAP: at most {@see Agreely::BATCH_CAP} (500) items per call. An over-cap list
     * throws AgreelyConfigError BEFORE any wire call, rather than spending a request
     * on a 422 that decides nothing. Split into chunks of 500 and note that each
     * chunk costs one request against the 120/minute company allowance.
     *
     * @param list<BatchCheckItem|array{customerRef:string,category:string,purpose:string}> $items
     * @return list<BatchDecision>
     */
    public function checkBatch(array $items): array
    {
        if ($items === []) {
            return [];
        }
        if (count($items) > self::BATCH_CAP) {
            throw new AgreelyConfigError(sprintf(
                'checkBatch: %d items exceeds the server cap of %d. Split the batch into chunks of %d or fewer.',
                count($items),
                self::BATCH_CAP,
                self::BATCH_CAP,
            ));
        }
        $wireItems = [];
        foreach ($items as $item) {
            if ($item instanceof BatchCheckItem) {
                $wireItems[] = $item->toArray();
            } else {
                $wireItems[] = $item;
            }
        }
        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/check/batch',
            body: ['items' => $wireItems],
            idempotentRetry: true,
        ));
        $out = [];
        foreach (Wire::objects($wire, 'decisions') as $d) {
            $out[] = BatchDecision::fromWire($d);
        }
        return $out;
    }

    /**
     * Ergonomic cartesian-product batch check. Builds all (customerRef, field)
     * pairs, calls checkBatch once, and returns a lookup for isAllowed() gates.
     * Sends category/purpose RAW. On an outage throws AgreelyUnavailableError.
     *
     * CAP: this builds refs x fields items, so it reaches the server's 500-item cap
     * FASTER than it looks: 100 rows x 6 fields is 600 items, over the cap. An
     * over-cap product throws AgreelyConfigError BEFORE any wire call, naming both
     * multiplicands so the fix is obvious (page the rows, or trim the field set).
     *
     * @param list<string> $customerRefs
     * @param list<array{category:string,purpose:string}> $fields
     */
    public function checkFields(array $customerRefs, array $fields): CheckFieldsResult
    {
        $total = count($customerRefs) * count($fields);
        if ($total > self::BATCH_CAP) {
            throw new AgreelyConfigError(sprintf(
                'checkFields: %d customerRefs x %d fields = %d cells, over the server cap of %d. '
                    . 'Page the customerRefs (at most %d per call with %d fields) or check fewer fields at a time.',
                count($customerRefs),
                count($fields),
                $total,
                self::BATCH_CAP,
                count($fields) > 0 ? intdiv(self::BATCH_CAP, count($fields)) : self::BATCH_CAP,
                count($fields),
            ));
        }
        $items = [];
        foreach ($customerRefs as $customerRef) {
            foreach ($fields as $field) {
                $items[] = new BatchCheckItem($customerRef, $field['category'], $field['purpose']);
            }
        }
        $decisions = $this->checkBatch($items);
        return new CheckFieldsResult($decisions);
    }

    /**
     * The reasoned form: the full decision object. A 200 deny returns normally
     * (deny is not an error). On an outage it THROWS AgreelyUnavailableError when
     * the policy fails closed, or returns a degraded allow (degraded:true) when the
     * explicit exception applies.
     *
     * @param array{onOutage?:'allow'|'deny'} $opts
     */
    public function checkDetailed(string $customerId, string $category, string $purpose, array $opts = []): CheckResult
    {
        return $this->resolve($customerId, $category, $purpose, $opts);
    }

    /**
     * Shared resolution. Sends category/purpose RAW. On a 200, returns the server
     * decision and clears any outage window. On an outage, applies the degrade
     * policy: a degraded allow returns a synthesized allow result; a fail-closed
     * outcome rethrows the outage error.
     *
     * @param array{onOutage?:'allow'|'deny'} $opts
     */
    private function resolve(string $customerId, string $category, string $purpose, array $opts): CheckResult
    {
        try {
            $wire = $this->transport->request(new RequestSpec(
                method: 'POST',
                path: '/v1/check',
                body: [
                    'customerId' => $customerId,
                    'category' => $category,
                    'purpose' => $purpose,
                ],
                idempotentRetry: true, // the check is a pure read; safe to retry
            ));
            $this->degrade->markSuccess();
            return CheckResult::fromWire($wire);
        } catch (AgreelyUnavailableError $error) {
            // Per-call explicit fail-closed shortcut.
            if (($opts['onOutage'] ?? null) === 'deny') {
                throw $error;
            }

            $decision = $this->degrade->evaluate($customerId, $category, $purpose, $opts, $error);
            if (!$decision->allow) {
                throw $error; // fail closed -> surface the outage
            }

            return new CheckResult(
                decision: 'allow',
                status: 'active',
                consentRef: null,
                checkedAt: gmdate('Y-m-d\TH:i:s\Z'),
                degraded: true,
                mode: $decision->mode,
            );
        }
    }
}
