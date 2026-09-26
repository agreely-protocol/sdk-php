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
  explicitly and per-category, to a scoped, audited fail-open. For a **purge job**,
  failing closed means **not purging**: see
  [Host retention](#host-retention-scope-retention).
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

## Host retention (scope `retention`)

« Agreely décide et surveille, l'hôte exécute et rend compte. » Agreely decides the
retention rules; your systems read them, run their purges, and **declare** what they
ran. Agreely records the declaration. It observes nothing in your systems and
verifies none of it, so every write answers `status: "declared"`, never `"verified"`.

```php
$rules = $agreely->retention()->listRules();          // archived rules included
// $rules->cursor    -> pass back as changedSince next time (it trails by a few minutes)
// $rules->ruleKeys  -> the COMPLETE current key set; a key that disappeared was deleted
$detail = $agreely->retention()->getRule($ruleKey);   // + the host's last declared purge and pass

$cells = $agreely->catalog()->listCells();            // every cell + the rule that governs it
$cells->gaps();                                       // cells with NO rule: abstain, never guess
```

A cell whose `retentionRuleKey` is `null` is the register's own gap, surfaced and
never filled: the host **abstains** and never picks a duration of its own.

### Declaring a purge

```php
use Agreely\Sdk\Types\PurgeMethod;

$declared = $agreely->retention()->declarePurge($ruleKey, [
    'ranAt'           => new DateTimeImmutable('now'),  // or RFC 3339 WITH an offset
    'recordsAffected' => 1_240,                          // 1 .. 1,000,000,000
    'method'          => PurgeMethod::DESTROYED,         // or ::ANONYMIZED
    'coveredFrom'     => '2024-01-01',
    'coveredUntil'    => '2024-06-30',
    'hostSystem'      => 'billing',                      // a STABLE slug, never a pod name
    'hostCategory'    => 'invoices',
], ['idempotencyKey' => "nightly-{$runId}:{$ruleKey}"]);
```

> **`method` is the past participle, `action` is the infinitive.** A rule's own
> `action` reads `destroy` / `anonymize`; a declaration of what you DID reads
> `destroyed` / `anonymized`. They are two separate classes,
> `RetentionAction` and `PurgeMethod`, so one cannot be mistaken for the other, and
> passing a rule's word is refused client-side with the word you meant. Derive it
> instead: `PurgeMethod::forRule($rule->action)`. There is no `aggregated`:
> aggregation is a technique recorded on an anonymisation process, not a third
> disposition.

An `anonymized` purge **requires** `anonymizationProcessKey` (never defaulted from
the rule) and a `destroyed` one refuses it. `references` is optional (your own
record identifiers, at most 1000 and never more than `recordsAffected`).

### Declaring a pass that found nothing

```php
$agreely->retention()->declareSweep($ruleKey, [
    'sweptAt'    => new DateTimeImmutable('now'),  // within the last 24 HOURS
    'hostSystem' => 'billing',
], ['idempotencyKey' => "nightly-{$runId}:{$ruleKey}:sweep"]);
```

The heartbeat: without it, a host with nothing to purge and a host that **stopped**
purging look the same. The floor is one pass per (rule, `hostSystem`) every 15
minutes; a second one is `AgreelySweepTooFrequentError`, which this SDK never
auto-retries. Do not loop on it: the pass already declared stands for this one.

### What is refused before the request leaves

All of these throw `AgreelyConfigError` with **no wire call**, because each one is a
sure 422 you would otherwise diagnose from a cron log at 3am:

- a missing, malformed or over-long `idempotencyKey`, **or one put in the body** (it
  is a header; the input shape is closed, so it cannot reach the body)
- a `method` outside `destroyed` / `anonymized`, an `anonymized` purge with no
  process key, a `destroyed` one with one
- `recordsAffected` below 1 (a pass that found nothing is `declareSweep`, not a purge
  of zero records) or above 1,000,000,000
- more than 1000 `references`, or more references than `recordsAffected`
- a `sweptAt` older than 24 hours: a queued retry from yesterday, or a cron on a
  skewed clock, is dropped here rather than failing remotely for a reason nobody guesses
- a `hostSystem` or `hostCategory` shaped like an id (a pod name, a uuid, a hash): a
  value that changes per deploy **burns one of the 10 host systems allowed per 30
  days** and locks you out within days. Choose `billing`, `crm`, `warehouse` once
- a naive timestamp with no offset (it would be read in the server's zone and
  silently re-date the evidence), or a `coveredFrom` / `coveredUntil` that is not
  `YYYY-MM-DD`

### The idempotency digest is bound to the API key

A replay belongs to the API key that declared. Another key of the same company
sending the same `Idempotency-Key` records **its own** declaration and never reads
the first one back. So if you **rotate** your API key while a declaration's response
was lost, retrying it with the new key records a **second** declaration. Settle every
pending declaration with the old key before you revoke it.

### A purge job fails closed by NOT PURGING

This is the opposite direction from `check()`, and it is a decision rather than a
default. The two ways stored rules can be wrong are not symmetrical:

- a rule was **shortened** and you missed it: you keep data somewhat too long. Minor,
  and the next run that reads the rules corrects it.
- a rule was **lengthened** (a legal hold, an investigation) and you missed it: you
  **destroy what you were required to keep**, and that does not repair.

So on an outage the job **abstains**:

```php
$forRun = $agreely->retention()->rulesForPurge(['snapshot' => $stored]); // $stored may be null

if (!$forRun->mayPurge()) {
    $log->warning('retention: abstained', ['reason' => $forRun->reason]);
    return;                     // do NOT purge this run
}
foreach ($forRun->rules() as $rule) { /* ... */ }
$store->put(json_encode($forRun->snapshot?->toArray()));   // for the next run
```

`rules()` **throws** on an abstain rather than handing back an empty list, because an
empty list reads as "nothing to purge" and would make a missed run look like a clean
one. Persist the snapshot with `->toArray()` and rebuild it with
`RetentionRuleSnapshot::fromArray()`; with a snapshot in hand the next run reads only
what changed.

Purging on stored rules is opt-in, bounded and audited:

```php
$forRun = $agreely->retention()->rulesForPurge([
    'snapshot'       => $stored,
    'onOutage'       => 'use-snapshot',                  // the explicit word
    'maxSnapshotAge' => '20h',                            // capped by maxDegradeWindow (24h)
    'onDegrade'      => fn ($ctx) => $audit->log($ctx),   // MANDATORY, else it throws
]);
$forRun->isDegraded();   // true when it proceeded on stored rules
$forRun->staleForMs;     // how old they were
```

**Anything that is not an outage throws.** A 401/403, a 402, a 422 and a 429 are not
"Agreely is down", and treating them as one would hide a key or billing problem behind
what looks like a skipped night.

## Inventory (scope `inventory`)

A host system declares the record sets it holds and the **names** of their fields,
never a value. Agreely links each set to a catalogue cell and hands back the frozen
retention statement you stamp on a record at collection.

> **Source note.** `/v1/inventory/*` is not in the committed `openapi.yaml` as of
> 2026-09-25; this resource is built from the shipped `InventoryController`. Treat a
> divergence as a question for the API rather than something to work around.

```php
$declared = $agreely->inventory()->replaceCategories([
    'hostSystem' => 'crm',
    'categories' => [[
        'key'    => 'beneficiaires',
        'label'  => 'Bénéficiaires',
        'labelEn' => 'Beneficiaries',                       // optional, never translated for you
        'fields' => [
            ['key' => 'nom',       'label' => 'Nom complet'],
            ['key' => 'naissance', 'label' => 'Date de naissance'],   // the NAME, never a date
        ],
    ]],
]);
$declared->withdrawn;   // how many sets this call withdrew
```

**`replaceCategories` sends a COMPLETE LIST, and what you omit is withdrawn.** It is
not an append: every set this `hostSystem` declared before that is missing from the
call is withdrawn (it stays listed, marked withdrawn). Always build the whole list
from one source of truth, and check `->withdrawn` on a run that meant to change
nothing. An **empty** list is refused rather than read as "withdraw everything",
because a serialisation bug must never retire a whole inventory in one call. Also
refused client-side: more than 50 sets, a set with no fields or more than 60, an
id-shaped key, and any member outside `key` / `label` / `labelEn` / `fields` (so a
`value` smuggled into a field object never leaves your process, and the refusal never
echoes what was sent).

```php
$sets = $agreely->inventory()->listCategories(['hostSystem' => 'crm']); // withdrawn ones included
$sets[0]->decision->isDecided();      // false -> no duration: ABSTAIN, never invent one
$sets[0]->retentionStatement?->text('fr');

$resolved = $agreely->inventory()->getStatement($statementKey);
$resolved->current;                    // false once superseded, and the terms are STILL the frozen ones
```

A statement is **frozen**: a record collected under « 3 mois » keeps a key that
resolves to « 3 mois » after the rule becomes 2. That is the point, not staleness to
correct.

The scopes differ per method, deliberately: `replaceCategories` needs `inventory`
(declaring an inventory shapes the register, so a purge cron holding `retention` must
not be able to rewrite what the organisation says it holds); `listCategories` accepts
`inventory` or `retention`; `getStatement` also accepts `check`, so a public
collection form can resolve the sentence it stamps without holding a key that writes.

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
| `AgreelySweepTooFrequentError` | 429 `sweep_too_frequent` - the per-(rule, hostSystem) 15-minute floor, a subclass of the above. NEVER auto-retried |
| `AgreelyConflictError`      | 409 `retry` - a declaration lost a race. Retry with the **same** Idempotency-Key |
| `AgreelyUnavailableError`   | 503 / network / timeout               |
| `AgreelyConfigError`        | bad client config, or input refused before the wire call |

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
- **Scopes** (`Agreely\Sdk\Types\Scope`): `check` authorizes `check`; `issue`
  authorizes the consent-request endpoints; `attest` authorizes manual consents;
  `relationship` authorizes the relationship end/revert; either `check` or `issue`
  reads `GET /v1/catalog`; `retention` authorizes the retention rules, the catalogue
  cells and the purge and pass declarations; `inventory` authorizes the inventory
  declaration and its reads. `registry` is in the vocabulary because a key can carry
  it and `identity()` will report it, but **no resource here wraps it**: it reads and
  writes a named customer's identity record, it is addressed only by a
  `customer_ref` you already hold, and it has no list endpoint by construction.
  `identity()` returns whatever the server sends, so a scope added later can reach
  you at runtime.

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
  the receipt over HTTPS. Resolving a citizen DID calls the Agreely CITIZEN tier
  (default `https://my.agreely.ca/did/{did}`), and `resolveCompanyDid` calls the
  Agreely WEB tier (default `https://app.agreely.ca/c/{slug}/did.json`); both are
  overridable, and injecting your own `resolver` removes them entirely.
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
