# agreely/sdk (PHP)

The thin, typed PHP client for the Agreely **/v1 consent API** (Law 25 / Loi 25).
One call to gate data use on a live, authoritative consent check. No database, no
ref tables, no local mirror - every `check()` is a fresh call to Agreely (caching
an allow while a revoke lands is a correctness failure, spec §16).

This is the PHP port of [`@agreely/sdk`](https://www.npmjs.com/package/@agreely/sdk),
the TypeScript reference client (source: https://github.com/agreely-protocol/sdk).
Both SDKs assert the **same shared golden vectors** (`vectors/vectors.json`)
against the live API, so neither drifts from the contract.

- **One-call DX.** `if ($agreely->check($id, $category, $purpose)) { ... }`
- **Typed end to end.** Typed result objects, typed errors, PSR-4 / PSR-12, phpstan max.
- **Fail-closed by default.** On an outage `check()` denies - unless you opt in,
  explicitly and per-category, to a scoped, audited fail-open.
- **PHP 8.2+**, `ext-curl` + `ext-json`. Bring your own HTTP client (PSR-18 adapter)
  or use the bundled minimal curl client.

## Install

```bash
composer require agreely/sdk
```

## Quickstart

```php
use Agreely\Sdk\Agreely;

$agreely = new Agreely(['apiKey' => getenv('AGREELY_API_KEY')]); // baseUrl optional

// Boolean gate - ALLOW is the only true. Send RAW human labels; Agreely
// normalizes server-side (never normalize them yourself).
if ($agreely->check('cust_8812', 'Phone number', 'Billing')) {
    // ...you may use the phone number for billing
}
```

### The reasoned form

```php
$d = $agreely->checkDetailed('cust_8812', 'Phone number', 'Billing');
// $d->decision   "allow" | "deny"   (ALLOW is the only true)
// $d->status     one of the eight values below (Agreely\Sdk\Types\CheckStatus)
// $d->consentRef "0x…"  (null for "none" and for "necessity")
// $d->assurance  "citizen_signed" | "company_attested"  (null for "none"/"necessity")
// $d->basis      the declared non-consent basis  (ONLY on "necessity", else null)
// $d->checkedAt  "2026-…Z"
```

A consent **deny is a normal 200** - `checkDetailed` returns it, it does not
throw. Errors (auth, validation, rate-limit, outage) throw typed errors.

### The status vocabulary

Two statuses allow, six deny:

| status | decision | what it means |
| --- | --- | --- |
| `active` | allow | a live consent record backs the cell |
| `necessity` | allow | **no consent record**; the catalog cell declares a non-consent lawful basis. Carries `basis`, no `consentRef`, no `assurance`. Creates nothing. |
| `none` | deny | no record on a consent-basis cell. Also what an **erased** cell reads as. |
| `revoked` | deny | the consent was withdrawn (art. 14) |
| `expired` | deny | the consent lifespan elapsed (art. 14 al. 3) |
| `relationship_ended` | deny | the company attested the purposes are accomplished (art. 23). A relationship-level stop: the per-cell consent stays truthfully active, it was never withdrawn. |
| `sensitive_requires_consent` | deny | no record, and the company declared the cell sensitive, so it fails closed to express consent (art. 12 al. 1 in fine / art. 13) |
| `erased` | deny | listed by `openapi.yaml`, **not currently emitted**. Erasure crypto-shreds the record, so an erased cell reads back as `none`. |

**A `necessity` allow is not a consent.** It rests on a basis the company
*declared* on its catalog (`contract`, `necessary_for_service`, `security_fraud`,
`legal_obligation`, `professional_contact`), there is no signed proof behind it,
and Agreely does not certify its legal validity. Never present it to a person or
an auditor as "consented":

```php
if ($d->isNecessity()) {
    // allowed, but on $d->basis, NOT on consent
}
```

Treat any status you do not recognise as a **deny**: read `$d->decision`, which is
only ever `allow` or `deny`.

### Issue a consent request (no UI)

```php
$r = $agreely->consentRequests()->create([
    'customerId'     => 'cust_8812',
    'recipientEmail' => 'person@example.com',
    // catalog entry ids AND/OR raw {category, purpose} pairs:
    // REQUIRED: the published consent document (the Law 25 s. 8 disclosure) the
    // request is issued under; the requested items derive from it server-side.
    'consentDocumentId' => '<documentVersionId>', // or: 'documentCode' => 'conditions-marketing'
    'validUntil'     => '2031-01-01',
]);
// $r->requestId  "0x…64hex"  (the protocol handle - the public id, NOT a uuid)
// $r->status "pending"; $r->deepLink; $r->emailDelivered; $r->items
```

`create` is **never auto-retried** (it emails). The SDK attaches a unique
`Idempotency-Key` per call; pass your own to make a retry replay the original 201
instead of issuing twice:

```php
$agreely->consentRequests()->create($input, ['idempotencyKey' => 'order-4471']);
```

### Idempotency

The server honours `Idempotency-Key` on **both** `consentRequests()->create` and
`manualConsents()->record`: a retry with the same key replays the original 201
byte-for-byte and records nothing new, so a dropped connection can never
double-issue or double-attest.

The key is the whole contract. The replay is keyed on **(company, key)** alone,
not on the request body and not on the endpoint. So:

- reusing a key with a **different payload** silently replays the *first* payload
  and writes nothing;
- a key already spent on `consentRequests()->create` will replay **that** response
  from `manualConsents()->record`.

Leave the key unset unless you have a durable, operation-unique id.

### End / revert a customer relationship (art. 23)

Attest that a customer relationship is over (Loi 25 art. 23, "les fins sont
accomplies") from your own offboarding flow, and undo a mistaken end within the
correction window (art. 11 / art. 28). Both require a `reason` and fail closed
client-side (`AgreelyConfigError`) on a blank one. Scope: `relationship`.

```php
$ended = $agreely->relationships()->end([
    'customerRef' => 'cust_8812',   // your OWN ref (the check ref), never a DID
    'reason'      => 'account closed; purposes accomplished',
]);
// $ended->status "ended"; $ended->endedAt; $ended->endedBy "company" | "citizen_request"

// Undo a premature/mistaken end (a correction, NOT a resurrection of dead consent):
$restored = $agreely->relationships()->revert([
    'customerRef' => 'cust_8812',
    'reason'      => 'offboarded the wrong account',
]);
// $restored->status "active"; $restored->reverted true
```

Ending is a pure lifecycle overlay: it never revokes, erases, or hides any
per-cell consent. A non-undo-eligible revert (citizen-driven end, past the
window, or after any destruction) is a clean 404 with nothing written.

### List / lookup / get / catalog

```php
$page = $agreely->consentRequests()->list([
    'customerId' => 'cust_8812',  // filter to one subject ref (optional)
    'status'     => 'pending',    // pending|approved|refused|expired|revoked_before_action (optional)
    'limit'      => 50,           // page size, default 50, max 100 (optional)
    'cursor'     => $cursor,      // a prior nextCursor (optional)
]);
// $page->items (list<ConsentRequestRecord>); $page->nextCursor (null when exhausted).
// Metadata only, newest first. Each record now carries ->customerId and ->documentCode.

$one     = $agreely->consentRequests()->get('0x…'); // the protocol requestId, NOT a uuid
$catalog = $agreely->catalog()->list();             // discovery for issuance
```

**Dedup before issuing.** `hasPending` answers "is a consent request already
outstanding for this customer?" so you do not re-issue (and re-email):

```php
if (!$agreely->consentRequests()->hasPending('cust_8812', 'conditions-marketing')) {
    $agreely->consentRequests()->create([
        'customerId'     => 'cust_8812',
        'recipientEmail' => 'person@example.com',
        'documentCode'   => 'conditions-marketing',
        'validUntil'     => '2031-01-01',
    ]);
}
```

The second argument (`documentCode`) is optional; omit it to match any pending
request for the customer. This is a **metadata convenience** over the list
endpoint, not a compliance decision: it reports whether a pending request exists,
it does not assert consent was given. A blank `customerId` throws
`AgreelyConfigError` before any wire call.

## Errors

Every failure is an `Agreely\Sdk\Errors\AgreelyError` subclass - a **deny is not
an error**.

| Error                       | When                                  |
| --------------------------- | ------------------------------------- |
| `AgreelyAuthError`          | 401 unauthorized / 403 forbidden      |
| `AgreelyValidationError`    | 400 / 422 (`->field` names the input) |
| `AgreelyNotFoundError`      | 404                                   |
| `AgreelyBillingInactiveError` | 402 - the company's Agreely subscription lapsed |
| `AgreelyRateLimitError`     | 429 (`->retryAfter` seconds)          |
| `AgreelyUnavailableError`   | 503 / network / timeout               |
| `AgreelyConfigError`        | bad client config (thrown at init)    |

```php
use Agreely\Sdk\Errors\AgreelyRateLimitError;

try {
    $agreely->check($id, $cat, $pur);
} catch (AgreelyRateLimitError $e) {
    sleep($e->retryAfter ?? 1);
}
```

Each error exposes `->code` (the wire code string), `->status` (HTTP status), and
`->field` (validation only).

A `402` `AgreelyBillingInactiveError` means the **company's** Agreely subscription
lapsed (trial ended unpaid, `past_due`, or canceled) - not an outage. `check()`
**fail-closes** to `false` (a lapsed biller never gets an accidental allow), while
`checkDetailed()` **throws** it so you can surface it distinctly. It is actionable
(the company must pay to restore service), so treat it apart from "Agreely is down".

```php
use Agreely\Sdk\Errors\AgreelyBillingInactiveError;

try {
    $agreely->checkDetailed($id, $cat, $pur);
} catch (AgreelyBillingInactiveError $e) {
    // Gate is closed AND the company must fix its billing. Surface, don't retry.
}
```

## Timeouts & retries

Low default timeout (**800ms** total budget). Only idempotent reads and the check
are retried on a transient outage (network / 503): up to 2 attempts, jittered,
inside the budget. `consentRequests()->create` is **never** retried.

```php
new Agreely(['apiKey' => $key, 'timeout' => 1200]); // ms, including retries
```

### Raise it if you are calling over the internet

800ms is sized for a call inside the **same datacentre or region** as the API. It
is not a safe budget over the open internet: a cold TLS handshake plus a
cross-region round trip can exceed it on a perfectly healthy server.

That matters because this SDK **fails closed**. A timeout is treated as an outage,
so `check()` returns `false` and `checkDetailed()` throws. An under-set timeout
does not give you a slow answer, it gives you a **spurious deny**, and the person
is shown less of their own data than they consented to.

```php
// Internet-facing caller: give it room.
new Agreely(['apiKey' => $key, 'timeout' => 8000, 'maxRetries' => 1]);
```

The default is deliberately left low rather than raised for everyone: this is a
synchronous gate on a request path, and an unattended multi-second default would
turn an Agreely outage into a multi-second hang on every one of your pages.
Choose the budget your deployment can actually pay.

## Limits

| limit | value | what happens past it |
| --- | --- | --- |
| batch size | **500** cells per `checkBatch` / `checkFields` | the SDK throws `AgreelyConfigError` **before** the wire call (`Agreely::BATCH_CAP`). Server-side it is a 422 that decides nothing. |
| rate | **120 requests/minute per company** | 429 with `Retry-After`, surfaced as `AgreelyRateLimitError` (`Agreely::RATE_LIMIT_PER_MINUTE`) |

The rate limit is per **company**, not per api key, so every key you issue shares
one allowance. One `checkBatch` of 500 cells costs **one** request, which is the
whole point of batching.

`checkFields` builds a `refs x fields` product, so it hits the cap sooner than it
looks: 100 rows x 6 fields is 600 cells. It refuses client-side and tells you the
safe page size:

```
checkFields: 100 customerRefs x 6 fields = 600 cells, over the server cap of 500.
Page the customerRefs (at most 83 per call with 6 fields) or check fewer fields at a time.
```

The rate limit is a deployment default (`API_RATE_LIMIT_PER_MINUTE`); a
self-hosted or negotiated deployment may differ. Treat it as the number to design
against, not a contract.

## Outage behavior - fail-closed by default

When Agreely is unreachable (503 / timeout / network), `check()` **denies**
(returns `false`); `checkDetailed()` **throws** `AgreelyUnavailableError`. A real
`200` deny is never affected by any of this.

You can opt specific categories into **fail-open**, but only explicitly, scoped,
and audited - two independent gates:

```php
$agreely = new Agreely([
    'apiKey' => $key,
    'degradeOnOutage' => [
        'mode'            => 'fail-open',                   // the explicit word
        'categories'     => ['Browsing/usage'],            // ONLY these may degrade  (gate 1)
        'maxOutageWindow' => '5m',                          // refuse to degrade past this
        'onDegrade'      => fn ($ctx) => $audit->log($ctx), // MANDATORY - absent, the constructor throws
    ],
]);

// gate 2: the call must ALSO opt in. Effective only because the category is
// allow-listed above. Without the config, a per-call opt-in still denies.
$agreely->check('cust_8812', 'Browsing/usage', 'Analytics', ['onOutage' => 'allow']);
```

Every degraded **allow** emits a `DegradeContext` via `onDegrade`
(`customerId`, `category`, `purpose`, `mode`, `breakGlass`, `error`, `at`).

### Bounding the window

`degradeOnOutage.maxOutageWindow` is capped at **24h** by default. A value over
the cap (e.g. `"9999h"`) throws `AgreelyConfigError` rather than opening an
effectively-unbounded fail-open window. Raise (or lower) the cap per client:

```php
new Agreely(['apiKey' => $key, 'maxDegradeWindow' => '12h']); // default "24h"
```

> Heads-up: a per-call `['onOutage' => 'allow']` that is **not** backed by a
> matching `degradeOnOutage.categories` entry has no effect - the check still
> **denies**. The SDK logs a one-time dev warning (via `error_log`) when this
> happens; silence it with the `AGREELY_SILENCE_WARNINGS` env var.

### Break-glass - omitted in PHP v1 (by design)

The TS SDK ships a third degrade gate: an operator **break-glass** lever - a
runtime, auto-expiring override engaged in-process during an active outage. PHP
requests are typically **request-scoped** with no long-lived in-process "engaged"
state, so a break-glass that lives in a single object would not survive across
requests and would give a false sense of a fleet-wide override.

PHP v1 therefore **omits** break-glass and ships the parts that port cleanly and
correctly to a request-scoped model: the fail-closed default, the two-gate
fail-open (config allow-list + per-call opt-in), the `maxOutageWindow` cap, and
the one-time ineffective-opt-in warning. A future version can add break-glass
backed by a shared store (PSR-16 cache or an injected callable) so the engaged
state is visible across every PHP worker. Everything else keeps parity with the
TS SDK exactly.

## Bring your own HTTP client

The SDK depends only on an `Agreely\Sdk\Http\HttpClient` (one method: `send`).
The default is a minimal curl client. Inject your own (e.g. a Guzzle/PSR-18
adapter, or a test double) via `httpClient`:

```php
new Agreely(['apiKey' => $key, 'httpClient' => $myClient]);
```

## Notes

- **Never normalize** category/purpose before sending - the server does it.
- **Labels are bilingual and accent-tolerant.** The `category` and `purpose`
  passed to `check()` may be sent in French OR English, with or without accents,
  and are matched case- and whitespace-insensitively. English resolves only when
  the company actually disclosed an English label for that cell. If a label is
  ambiguous or undeclared the check fails closed (deny / `none`), so pass the
  label as declared in the catalog when you can.
- The public identifier everywhere is the **protocol `requestId`** (`0x` + 64
  hex), never an internal uuid; `consentRef` is `0x`-hex and **absent** when
  status is `none`.
- Scopes: `check` authorizes `check`; `issue` authorizes the consent-request
  endpoints; `attest` authorizes manual consents; `relationship` authorizes the
  relationship end/revert; any scope reads the catalog.

## Open and auditable

MIT-licensed and built to be provable, not just trusted:

- **No telemetry, no analytics, no phone-home.** No third-party trackers, no
  hidden call to an Agreely-controlled server, no data collection. Every network
  call is in the source.
- **Only the endpoints you configure.** The client contacts your configured
  Agreely API base URL (default `https://api.agreely.ca`). The opt-in receipt
  verifier additionally contacts a chain RPC you pass in (on-chain anchor) and an
  IPFS gateway (default `gateway.lighthouse.storage`, overridable) for the opt-in
  disclosure-copy check; its `did:web` resolver fetches the issuer host named in
  the receipt over HTTPS.
- **Minimal deps, no install scripts.** `ext-curl` + `ext-json`; bring your own
  PSR-18 HTTP client if you prefer.
- **Audit surface.** `src/Http/CurlHttpClient.php` and
  `src/Verify/ReceiptVerifier.php` are the only files that open a socket.

Agreely records and structures consent; it does not certify that your
organization is compliant.

## The TypeScript SDK

Prefer Node? The same client, same contract, same golden vectors:

- `@agreely/sdk` on npm: https://www.npmjs.com/package/@agreely/sdk
- Source: https://github.com/agreely-protocol/sdk

## Links

- Product and API: https://agreely.ca
- Organization: https://github.com/agreely-protocol

## Development

```bash
composer install
composer test          # fast offline unit suite (mock transport)
composer stan          # phpstan level max
vendor/bin/phpcs       # PSR-12

composer test:contract # live contract + golden-vector parity
```

The unit suite is fully offline (mock transport) and always runs. The contract
suite (`composer test:contract`) asserts the PHP SDK against a live `/v1` API
**and** the shared golden vectors (`vectors/vectors.json`) - the cross-SDK
anti-drift gate (PHP == TS == the contract). Without a seeded fixture from a
running Agreely stack it skips cleanly.
