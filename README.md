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
// $d->status     one of the nine values below (Agreely\Sdk\Types\CheckStatus)
// $d->consentRef "0x…"  (null for "none" and for "necessity")
// $d->assurance  "citizen_signed" | "company_attested" | "company_documented"
//                (null for "none"/"necessity" and for an acknowledged informed line)
// $d->tier       "full" | "manual" | "verbal"  (present exactly when assurance is)
// $d->basis      the declared non-consent basis  (ONLY on "necessity", else null)
// $d->validUntil "2027-10-09T03:59:59Z"  the backing consent's end (null without a record)
// $d->revokedAt  "2026-10-08T20:08:52Z"  when it was withdrawn (ONLY on "revoked", else null)
// $d->checkedAt  "2026-…Z"
```

A consent **deny is a normal 200** - `checkDetailed` returns it, it does not
throw. Errors (auth, validation, rate-limit, outage) throw typed errors.

`validUntil` is on every answer backed by a consent record (`revoked`, `expired` and
`relationship_ended` included) and null when there is none (`none`, `necessity`, the
named refusals). It is an **upper bound, never a cache lease**: a withdrawal ends the
consent before that date and reaches only a host that checks again, and a renewal moves
it. Read it on every check; never cache an allow until it.

### Health, caching and failing closed

- Call `identity()` (GET /v1/whoami) about **once a minute from a single scheduler** (a
  cron, a leader), never per request or per worker: the 120 requests a minute are shared
  by every key of the organisation.
- On a **401** (`AgreelyAuthError`) or a **402** (`AgreelyBillingInactiveError`),
  **purge anything you cached from Agreely and fail closed**.
- **Never cache `check()`.** A withdrawal must deny on the very next call.
- **Never decide consent from a cached catalog.** A catalog cell says what the
  organisation declared, not what a person consented to.

```php
$who = $agreely->identity();
$who->scopes;                    // the key's own scopes
$who->company?->statute;         // "P-39.1" or "A-2.1": read it before switching on a legalBasis
$who->company?->publicPolicyUrl; // the absolute url of the public privacy page, or null
```

### The status vocabulary

Two statuses allow, seven deny:

| status | decision | what it means |
| --- | --- | --- |
| `active` | allow | a live consent record backs the cell |
| `necessity` | allow | **no consent record**; the catalog cell declares a non-consent lawful basis. Carries `basis`, no `consentRef`, no `assurance`. Creates nothing. |
| `none` | deny | no record on a consent-basis cell. Also what an **erased** cell reads as. |
| `revoked` | deny | the consent was withdrawn (art. 14) |
| `expired` | deny | the consent lifespan elapsed (art. 14 al. 3) |
| `relationship_ended` | deny | the company attested the purposes are accomplished (art. 23). A relationship-level stop: the per-cell consent stays truthfully active, it was never withdrawn. |
| `requires_depersonalization` | deny | the cell declares a ground the act carries only for depersonalized information (A-2.1 art. 65.1 al. 2 (4°)). Not "no consent on record": never answer it by collecting a consent. |
| `basis_not_in_regime` | deny | the cell declares a ground the tenant's act does not carry (typically the other sector's vocabulary). The fix is a catalog re-declaration. |
| `erased` | deny | listed by `openapi.yaml`, **not currently emitted**. Erasure crypto-shreds the record, so an erased cell reads back as `none`. |

**A `necessity` allow is not a consent.** It rests on a basis the company
*declared* on its catalog (`contract`, `necessary_for_service`, `security_fraud`,
`legal_obligation`, `professional_contact` for a private enterprise under P-39.1;
`attributions`, `programme`, `entente_collecte`, `compatible_use`,
`manifest_benefit`, `law_application`, `public_character` for a public body under
A-2.1; see `CheckBasis::PRIVATE` / `CheckBasis::PUBLIC`), there is no signed proof behind it,
and Agreely does not certify its legal validity. Never present it to a person or
an auditor as "consented":

```php
if ($d->isNecessity()) {
    // allowed, but on $d->basis, NOT on consent
}
```

Treat any status you do not recognise as a **deny**: read `$d->decision`, which is
only ever `allow` or `deny`.

**`sensitive_requires_consent` is gone** (no longer emitted since 2026-09-28, and the
constant was removed in 0.4.0). A cell declared sensitive answers by its basis like
any other: on `consent` it denies `none` without a record (and that consent must be
express), on a non-consent ground it allows `necessity` with its `basis`.

**An acknowledged informed line is not a consent.** A line a document gives for
information, recorded as the person's acknowledgement on a paper or telephone
record, answers exactly like no record (`necessity` + `basis`, or `none`), with no
`consentRef`, no `assurance` and no `tier`. Once withdrawn it denies `revoked` with
a `consentRef`, still with no `assurance` or `tier`.

### Tier and assurance: the host decides

Every record-backed answer names the proof behind it twice: `assurance`
(`Agreely\Sdk\Types\Assurance`) and `tier` (`Agreely\Sdk\Types\ConsentTier`).

| tier | assurance | what backs it |
| --- | --- | --- |
| `full` | `citizen_signed` | the person signed with their own passkey |
| `manual` | `company_attested` | the company attests to a hand-signed paper |
| `verbal` | `company_documented` | the person consented **by telephone** and the organisation documented it; there is no document at all |

A manual or verbal consent allows **identically** at the gate; only the tier
differs, and **your code decides** what each tier may unlock. Neither P-39.1 s. 14
nor A-2.1 s. 53.1 requires a consent to be in writing, so this is your choice of
evidence, not a statutory threshold. Treat an assurance or tier you do not know as
**not acceptable**:

```php
use Agreely\Sdk\Types\ConsentTier;

$d = $agreely->checkDetailed('cust_8812', 'Revenu', 'Étude de crédit');
if ($d->isAllow() && ($d->isNecessity() || ConsentTier::atLeast($d->tier, ConsentTier::MANUAL))) {
    // this use requires at least a signed paper
}
```

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

**`validUntil`, on every consent write** (consent request, paper, telephone): a plain
date (`2031-01-01`) means through the **end of that calendar day in the organisation's
timezone**; an instant must be RFC 3339 **with an offset**. A relative phrase
(`+1 year`, `next year`) is refused, and so is an end more than **ten years** after the
consent's start. The ten-year ceiling is an Agreely product rule, not a statutory
limit: the acts limit a consent to the time its purposes need.

`create` is **never auto-retried** (it emails). The SDK attaches a unique
`Idempotency-Key` per call; pass your own to make a retry replay the original 201
instead of issuing twice:

```php
$agreely->consentRequests()->create($input, ['idempotencyKey' => 'order-4471']);
```

### Idempotency

The server honours `Idempotency-Key` on `consentRequests()->create`,
`manualConsents()->record`, `verbalConsents()->record`,
`verbalConsents()->confirmWithPaper`, `withdrawals()->record`,
`retention()->placeHold` and `retention()->releaseHold`: a retry with the same key
replays the original answer and records nothing new, so a dropped connection can never
double-issue or double-attest. The SDK generates a key per call; pass your own only
when you have a durable, operation-unique id.

`manualConsents()->createConsentSheet` is the exception: its key is a **latch**, not a
replay. The same key and body mints nothing and answers 409 `already_minted`, because
the printed reference and the claim token are never stored to be replayed.

- On the **manual and verbal** endpoints the key is **bound to the endpoint and to the
  request body**: the same key with a different body is a new request, and a key
  spent on one endpoint never replays another. It must be 1 to 255 printable ASCII
  characters; the SDK refuses anything else before the call (`AgreelyConfigError`).
- On `consentRequests()->create` the replay is keyed on **(company, key)** alone:
  reusing a key with a different payload replays the *first* payload and writes
  nothing.

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
    'status'     => 'pending',    // a ConsentRequestStatus value (optional)
    'limit'      => 50,           // page size, default 50, max 100 (optional)
    'cursor'     => $cursor,      // a prior nextCursor (optional)
]);
// $page->items (list<ConsentRequestRecord>); $page->nextCursor (null when exhausted).
// Metadata only, newest first. Each record now carries ->customerId and ->documentCode.
// A filter or cursor must be a single string: a list is refused before the call.

$one     = $agreely->consentRequests()->get('0x…'); // the protocol requestId, NOT a uuid
$catalog = $agreely->catalog()->list();             // discovery for issuance

// One published document's active cells, its current version and the regime, for one intake screen:
$form = $agreely->catalog()->forDocument('conditions-marketing');
$form->documentVersionId;   // what the consent writes take
$form->entries[0]->legalBasis;
```

**`approved` means a consent was obtained.** It means the person confirmed and, when
the request carried consent asks, accepted **at least one**. A person who confirmed
only her receipt of the lines given for information and declined **every** ask reads
`asks_declined` (`ConsentRequestStatus::ASKS_DECLINED`): no consent was obtained, it is
never returned as `approved` nor under `?status=approved`, and it is terminal, so
`waitForSettlement()` returns on it. Before 0.4.0 that outcome read `approved`.

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

## Consent documents (scopes `check` or `issue`)

Every consent write takes a `documentVersionId`; this is where it comes from. Pin a
document by its stable `code` and resolve the current version through it: a version id
goes stale at the next publication.

```php
$docs = $agreely->consentDocuments()->list();              // every published document
$doc  = $agreely->consentDocuments()->get('conditions-marketing');
$doc->documentVersionId;                                     // the version in force
$doc->disclosure->withdrawal->text('fr');                    // the published text, verbatim, never translated for you

// The information document of one version, as PDF bytes to print and hand to the person
// ('attest' and 'attest_verbal' keys may fetch it too):
$info = $agreely->consentDocuments()->getInformationPdf($doc->documentVersionId, ['locale' => 'fr']);
file_put_contents('/tmp/' . ($info->filename ?? 'information.pdf'), $info->pdf);
```

`getInformationPdf` is by **version id**, never by code: the version a consent is
recorded against is the one whose text the person must be given. A superseded version
is served too (and says so); a draft never is. English is refused (422 code
`english_text_missing`) when a section has no English text. The server renders the PDF,
so the call's budget defaults to the client's `timeout` or 15 s, whichever is larger
(`'timeout' => ms` overrides it). Fetching it records nothing: it is not evidence that
anyone was informed.

## Paper consents (scope `attest`)

Record a consent the person signed on paper. `items` names the consent **asks ticked
on the sheet**, and may be empty (every ask answered "no"). The server adds every line
the document gives for information as an acknowledgement (never a consent) and
ignores one you post.

```php
$paper = $agreely->manualConsents()->record([
    'customerId'        => 'cust_8812',
    'documentVersionId' => $documentVersionId,
    'effectiveDate'     => '2026-09-29',
    'validUntil'        => '2027-09-29',
    'items'             => [['category' => 'Courriel', 'purpose' => 'Infolettre']],
    'evidence'          => ['pdfSha256' => Agreely::hashPdfFile($path)],  // add 'pdf' => base64 to escrow it
]);
$paper->acknowledged;   // list<AcknowledgedLine>: the informed lines, never consents
$paper->asksDeclined;   // true when no ask was consented
```

Two attestations, each sent only as JSON `true` (a non-boolean is refused before the
call, `false` is the same as leaving it out):

- `'versionAttested' => true` is **required** when the paper was signed before the
  chosen version was published in Agreely: the organisation attests the signed document
  asked consent for the same purposes, with the same information, as that version
  (otherwise 422, reason `version_attestation_required`).
- `'sensitiveExpressAttested' => true` is **required** when a ticked purpose is
  sensitive and rests on the consent basis: the consent was express (otherwise 422,
  reason `sensitive_express_required`).

Refused with a 422: a document that asks no consent (a collection notice), an empty
file or the SHA-256 of zero bytes, a file that is not a PDF, an escrowed PDF that does
not hash to `pdfSha256`, an `effectiveDate` instant in the future by any amount
(`effective_date_in_future`), and the `validUntil` rules above. Refused with a 409
(`AgreelyConflictError`, code `conflict`; read `->reason`): a purpose already held by
an active passkey consent (`stronger_consent_active`), a paper for a customer and
document while a **verbal consent awaits its paper** (`verbal_awaits_paper`: send that
paper with `verbalConsents()->confirmWithPaper()`), an ended relationship
(`relationship_ended`), a paper signed before a withdrawal recorded for the same
purpose (`predates_withdrawal`), and a new sheet over a consent still in force that
would end before it (`renewal_ends_before_current`). A new sheet that ends no earlier
is accepted and the gate moves to it; withdrawing either one later withdraws both.

`createClaimLink()` answers `AgreelyNotFoundError` (reason `unknown_customer`) for a
customer the company holds no record of, and `AgreelyConflictError` (reason
`relationship_ended`) once the relationship has ended.

`revoke()` withdraws a manual **or verbal** cell and says what the gate does now in
`->gate`: `denied` (this consent backed it), `superseded` (a later consent for the
same purpose already backs the gate, which **still allows**), or `unchanged` (an
idempotent repeat). A withdrawal attaches to the **purpose**: withdrawing the manual
cell that confirmed a verbal one withdraws that verbal cell too, and so is every other
consent still running for it. `erase()` reports `->gate` the same way.

When the person later signed the same purpose **online with her passkey**, that
signed consent holds the gate and `revoke()` / `erase()` never end it: they answer 409,
reason `citizen_consent_at_gate`, and write nothing. Record her withdrawal with
[`withdrawals()->record()`](#withdrawals-scope-withdraw), which withdraws both at one
instant (then erase, if that is what was asked).

### The signature sheet, printed with its claim

Print the sheet the person signs, for one customer, minted with the claim she may
later redeem:

```php
$sheet = $agreely->manualConsents()->createConsentSheet('cust_8812', [
    'documentVersionId' => $documentVersionId,   // a PUBLISHED version that asks consent
    'locale'            => 'fr',                 // default "fr"
]);
$printer->print($sheet->signatureSheet->bytes()); // the BLANK sheet, with the reference printed on it
$sheet->printedReference;                         // e.g. "ABCD-EFGH"
$mailer->send($person, $sheet->claim->claimUrl);  // ANOTHER channel, never with the sheet
```

**Two rules the host must keep:**

1. **Never send the claim link in the same envelope or message as the sheet.** The
   printed reference is the second factor of the link; it only defends a link that
   travels separately. Both together make it decorative.
2. **Never send the blank sheet's hash as `evidence.pdfSha256`.** The evidence of the
   consent is the **signed** sheet, hashed once it comes back and recorded with
   `record()`. That is why the response carries no hash.

The reference and the claim token are returned **once** and never kept in clear: no
later call recovers them. A new sheet retires the claim of the previous one, so the
reference printed on it stops working. Refusals: 404 `unknown_customer`, 422
`invalid_document` (not a published version of yours) or code `no_consent_ask` (reason
`notice_only`: the version asks nothing), 409 `relationship_ended`, 409
`already_minted` (the Idempotency-Key latch).

## Telephone consents (scopes `attest_verbal` and `attest`)

A consent the person gave **by telephone**, documented by the organisation: the
weakest of the three tiers, since there is no document at all. Every result and
every `/v1/check` answer names it tier `verbal`, assurance `company_documented`;
never call it signed, validated or certified.

```php
use Agreely\Sdk\Types\RepresentativeCapacity;

$call = $agreely->verbalConsents()->record([
    'customerId'        => 'cust_8812',
    'documentVersionId' => $documentVersionId,
    'answers'           => [                          // every ask put on the call, "yes" or "no"
        ['category' => 'Courriel', 'purpose' => 'Infolettre', 'answer' => 'yes'],
        ['category' => 'Courriel', 'purpose' => 'Sondages',   'answer' => 'no'],
    ],
    'obtainedAt'        => new DateTimeImmutable('now'),   // the call; RFC 3339 with an offset, at most 7 days old
    'obtainedBy'        => 'Julie, service client',
    'scriptVersion'     => 'script-2026.09',
    'respondent'        => ['consentedBy' => 'self'],
    'validUntil'        => '2027-09-29',
    'paperExpected'     => true,
]);
$call->tier;           // "verbal"
$call->consentRefs;    // the "yes" purposes and the acknowledged lines

// For a minor under 14: 'isMinor' => true, and the respondent is the parent or tutor.
// 'respondent' => ['consentedBy' => 'representative',
//     'representativeCapacity' => RepresentativeCapacity::TITULAIRE_AUTORITE_PARENTALE,
//     'name' => 'Marie Tremblay'],
```

- Answer **only** the consent asks, each once, with the string `yes` or `no` (a
  boolean is refused before the call). A line given for information is never
  answered: the server refuses it (422) and records it itself as acknowledged.
- `sensitiveExpressAttested` must be `true` when a "yes" purpose is sensitive and
  rests on the consent basis. `isMinor`, `paperExpected` and
  `sensitiveExpressAttested` are sent only when `true`.
- `obtainedAt` may not be in the future by any amount (422, reason
  `obtained_at_in_future`); `scriptVersion` is your own label for the script read.
- A purpose already held by an active paper or passkey consent (reason
  `stronger_consent_active`, or `all_covered` when every ask is held), a relationship
  that has ended (`relationship_ended`), or a call dated before a withdrawal recorded
  for the same purpose (`predates_withdrawal`) is a 409 `AgreelyConflictError` (code
  `conflict`): do not retry.
- The organisation's daily limit of verbal consents is a 429
  `AgreelyVerbalDailyCapError`, which the SDK never auto-retries.

**Renewing a paper consent by telephone.** A signed paper consent whose end falls in
its **last 30 calendar days** (an Agreely product rule, not a statutory period) can be
renewed by phone with this same `record()` call. A "yes" for that purpose is a **new**
consent, the paper is never rewritten, and `/v1/check` answers tier `verbal` with the
new `validUntil` until the new signed paper comes back (`confirmWithPaper()`, which
raises it to `manual` with that same `validUntil`). A host requiring `manual` therefore
sees `verbal` between the call and the paper. The renewal may not end before the paper
(409 `renewal_ends_before_current`: a shorter consent is a withdrawal, record one
instead) nor be dated before its signature (409 `renewal_predates_current`). A "no" at
renewal withdraws nothing. Withdrawing either consent later withdraws both. A passkey
consent is never renewed by telephone.

**The signed paper came back** (scope `attest`: a key holding only `attest_verbal`
gets a 403, because the paper mints a company-attested consent):

```php
$rise = $agreely->verbalConsents()->confirmWithPaper($call->consentId, [
    'signedAt' => new DateTimeImmutable('now'),        // the evidence date, no earlier than the call
    'answers'  => [['category' => 'Courriel', 'purpose' => 'Infolettre', 'answer' => 'yes']],
    'evidence' => ['pdfSha256' => Agreely::hashPdfFile($path)],
]);
$rise->confirmed;   // each verbal cell with the NEW manual cell the gate now reads
$rise->withdrawn;   // the purposes unticked on the paper, withdrawn as of signedAt
```

The verbal consent is never rewritten: each ticked purpose moves into a new manual
consent dated from the call, and an unticked one is recorded as a withdrawal dated at
the signature. `answers` holds exactly the purposes still consented by telephone. One
paper per verbal consent (a second is a 409).

```php
$history = $agreely->verbalConsents()->get($call->consentId);   // 'attest_verbal' or 'attest'
$history->state;                 // awaiting_paper | verbal_only | paper_received
$history->purposes[0]->answer;   // yes | no | informed
$history->purposes[0]->confirmedBy;   // the manual cell to withdraw once the paper came back
```

Withdraw a verbal cell with `manualConsents()->revoke()` (`attest` or
`attest_verbal`; a verbal-only key reaches verbal refs only), erase it with
`manualConsents()->erase()` (`attest` only). A verbal ref whose paper came back
answers 409 (reason `superseded_by_paper`): act on the confirming manual ref instead.

## Withdrawals (scope `withdraw`)

Record, **on the person's behalf**, the withdrawal she asked you for, by any channel,
of **any** consent ask you hold for her: a paper or telephone consent, and a consent she
signed online with her passkey. `/v1/check` denies at once.

```php
$w = $agreely->withdrawals()->record('cust_8812', $consentRef, [
    'channel'     => 'phone',                  // phone | email | mail | in_person | other
    'operator'    => 'intervenant-0042',       // YOUR opaque staff id: never a name, never an email
    'requestedAt' => '2026-10-08T09:15:00-04:00', // optional: when she asked, as declared
    'reason'      => 'Ne souhaite plus recevoir l\'infolettre.', // optional, at most 1000 characters
]);
$w->gate;            // "denied" | "superseded" | "unchanged": what /v1/check does now
$w->alsoWithdrawn;   // the other consents of the purpose withdrawn at the same instant
$w->recordedOnBehalf; // always true; $w->assurance is always "company_attested"
```

- **Honest by construction.** The answer always says `recordedOnBehalf: true` and
  `assurance: "company_attested"`: Agreely records that the organisation says it
  received and actioned the request; it did not witness it, and it is never the
  person's own signature. The answer is the same whatever the consent's tier.
- **A withdrawal attaches to the purpose.** The other consents of the purpose still
  running in its chain (a renewal and the paper it renewed, a telephone consent and its
  paper, and a consent she signed online at or before `requestedAt`) are withdrawn at
  the same instant and listed in `alsoWithdrawn`. Read `gate`, never `withdrawn` alone:
  `superseded` means another consent still holds the gate.
- **Its own scope**, never pre-ticked and never implied by `attest`, because it is the
  only /v1 door to a consent the person signed. Ask for it explicitly.
- **The one route a company behind on its payments keeps**: honouring a withdrawal is a
  legal duty, not a paid service, so it never answers the 402.
- Accepted after the relationship ended. Idempotent: a repeat answers
  `alreadyWithdrawn: true`, `gate: "unchanged"`.
- Refusals (read `->reason`): 404 `unknown_consent` (an unknown, malformed or foreign
  reference, and another customer's consentRef, all answer the same), 409
  `consent_lapsed` (it ended on its own date: re-read `/v1/check`), 422 `not_revocable`
  (the cell was never a consent ask), `requested_at_in_future`,
  `requested_at_before_grant`, and the 429 `AgreelyWithdrawalDailyCapError` (at most 50
  per rolling 24 hours unless the operator set another value; no Retry-After; record
  further withdrawals from the customer record in Agreely). The SDK refuses a channel
  outside the list, an operator shaped like an email or a name, a `requestedAt` with no
  offset and an over-long reason before the call.

## Customer registry (scope `registry`)

The identity Agreely holds for one of your customers, addressed by one `customerRef`
you already hold. There is no list, by construction.

```php
$record = $agreely->customers()->upsert('cust_8812', [
    'displayName'  => 'Marie Tremblay',
    'email'        => null,               // null or "" CLEARS a field
    'legalBasis'   => 'contract',         // a NON-consent ground of your act
    'noticeLocale' => 'fr',
]);
$record->created;          // true when this call created the record (201)
$record->hasDisplayName;   // metadata only: never the name, the email or the note

$agreely->customers()->get('cust_8812');  // the same metadata, with the relationship's status
```

**`upsert` is a merge, never a replace:** a field you leave out is untouched, `null` or
`""` clears it, a value writes it. So a sync that only knows addresses never wipes a
name someone curated in Agreely. A read returns booleans for every personal field: a
per-reference read that returned an address would be a bulk export with a loop around
it. `legalBasis: "consent"` is refused (a client held on consent belongs to the consent
flow). Once a declared destruction removed the identity, a value is a 409 code
`identity_erased` (a clear still works); while a hold on all the information keeps it,
any change is a 409 code `identity_held`.

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

### The holds a purge must skip (scope `holds`)

A retention hold keeps one customer's information despite the rules: a rights request
the person made (P-39.1 s. 36 / A-2.1 s. 102.1), or another law. A purge job reads the
holds in place **before** it purges anything. The feed carries **references and scope,
never the reason**. `holds` is never granted by default: a purge job's key carries
`retention` **and** `holds`.

```php
$sync = $agreely->retention()->syncHolds(['changedSince' => $storedCursor]); // null: a full snapshot
foreach ($sync->holds as $hold) {
    $heldSet->upsert($hold->id, $hold);       // at least once: upsert by id
}
// mode "snapshot" REPLACES your whole active set; "delta" lists what was placed or released since
$store->put($sync->cursor);                   // the next sync's changedSince

if ($hold->scope->covers($ruleKey, $cellKey)) { /* skip this record */ }
```

**Purge only after `syncHolds()` returns.** It reads every page (500 rows each) and
collects them; any error (401, 403, 402, a 5xx, a timeout) **throws**, and a sync longer
than `maxPages` (default 1000) throws rather than return a partial set. On any throw,
purge nothing this run and store no cursor. `listHolds()` reads one page and
`holdPages()` yields them one by one, for a job that streams.

### One customer's retention (scope `registry`)

```php
$posture = $agreely->retention()->getCustomerRetention('cust_8812');
$posture->clock->dueAt;     // the earliest horizon of a rule NO hold suspends, or null
$posture->clock->reason;    // why null: no_declared_rule | relationship_active | hold_active
$posture->clock->rules[0]->held;   // suspended by a hold: its date is arithmetic, not a date to act on
$posture->disposition;      // the standing declaration, or null
$posture->activeHolds();

$hold = $agreely->retention()->placeHold('cust_8812', [
    'ground'    => 'other_law',                      // or 'rights_request'
    'provision' => 'Loi sur les impôts, art. 35',    // REQUIRED for other_law, refused otherwise
    'scope'     => 'all',                            // or ['rules' => [...], 'cells' => [...]]
    'reviewOn'  => '2027-10-09',                     // a reminder only: nothing ever releases a hold on it
]);

$released = $agreely->retention()->releaseHold('cust_8812', $hold->hold->id, [
    'reason' => 'Recours épuisés.',                  // REQUIRED: lifting a hold is a motivated act
]);
$released->agreelyIdentity;   // "erased" when this release removed Agreely's copy of the identity

$declared = $agreely->retention()->declareDisposition('cust_8812', [
    'disposition' => 'destroyed',                    // destroyed | anonymized | legal_hold
]);
$declared->appended;                     // false: an identical standing declaration was replayed
$declared->declaration->agreelyIdentity; // erased | none_held | retained_hold | retained
$declared->warnings;                     // hold_active: recorded as received, never refused
```

- **The clock never invents a period.** With no declared rule, `dueAt` is null with
  reason `no_declared_rule`, never a defaulted 12 or 24 months. The recipe ships beside
  the answer so you recompute the date rather than trust it.
- **A disposition is declared against an ended relationship only** (409 code
  `relationship_active` otherwise): end it with `relationships()->end()`, or place a hold
  to keep information while it runs. Append-only: a correction is a superseding
  declaration. `legal_hold` requires a `reason` naming the law; `retentionUntil` applies
  to it only.
- 🔴 **Irreversible side effect.** An appended `destroyed` or `anonymized` declaration
  removes the name, email, basis note and notice language Agreely holds for the
  customer, unless an active hold on **all** the information keeps them; releasing that
  last hold then removes them. `agreelyIdentity` says what happened. Existing backups
  are not altered.
- A host never links a rights request; the rights register places its own holds. At
  most 50 active holds per customer (422 code `too_many_holds`) and 1000 placed through
  /v1 per organisation per 24 hours (429 `AgreelyDailyCapError`, code
  `hold_budget_exhausted`). Releasing a hold your system did not place counts against a
  rolling 24-hour cap (code `hold_release_cap_reached`) and the workspace owner is
  emailed; an already released hold is a 409 code `already_released`.
- Everything here is **declared, never verified**: Agreely observes nothing in your
  systems.

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
refused client-side: more than 200 sets, a set with no fields or more than 60, an
id-shaped key, and any member outside `key` / `label` / `labelEn` / `fields` (so a
`value` smuggled into a field object never leaves your process, and the refusal never
echoes what was sent). Server-side, a set label holds 1 to 120 characters and a field
label 1 to 200, and an organisation introduces at most **1000 new set keys** in any
rolling 30 days (422 code `new_set_limit`): a key declared before is always accepted,
so re-declaring the same list always passes; renaming keys on every call does not.

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
| `AgreelyDailyCapError`      | 429 on a rolling 24-hour cap: `hold_budget_exhausted`, `hold_release_cap_reached`, and the two subclasses below. A subclass of the rate-limit error. NEVER auto-retried |
| `AgreelyVerbalDailyCapError` | 429 `verbal_daily_cap` - the organisation's daily limit of verbal consents |
| `AgreelyWithdrawalDailyCapError` | 429 `withdrawal_daily_cap` (reason `daily_cap`) - withdrawals recorded over /v1 in 24 hours. No Retry-After |
| `AgreelyConflictError`      | 409. Code `retry` (`->isRetryable()`): a declaration lost a race, retry with the **same** Idempotency-Key. Code `conflict` (`->isStateConflict()`): the request contradicts the record's state, `->reason` says which; retrying changes nothing. Other codes name their state: `identity_held`, `identity_erased`, `already_released`, `already_minted`, `relationship_active` |
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

Each error exposes `->code` (the wire code string), `->status` (HTTP status),
`->field` (the input the refusal is about, when the server named one) and `->reason`.

**Branch on `->reason`, never on the message.** Every refusal of the manual, verbal,
claim-link, consent-sheet and withdrawal routes carries a stable `reason` beside a
generic `code`; the registry, holds, dispositions and inventory carry their stable
string in `code` instead. `Agreely\Sdk\Errors\ErrorReason` and `ErrorCode` name the
known values. A reason the server adds later is readable as a plain string: it never
throws and never maps to another value, so fall back on `->code` for it.

```php
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\ErrorReason;

try {
    $agreely->manualConsents()->revoke($consentRef);
} catch (AgreelyConflictError $e) {
    if ($e->hasReason(ErrorReason::CITIZEN_CONSENT_AT_GATE)) {
        $agreely->withdrawals()->record($customerRef, $consentRef, ['channel' => 'phone', 'operator' => 'agent-12']);
    }
}
```

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
inside the budget. No write is ever auto-retried. With `maxRetries`, a read is
retried on a 429 only for the per-minute window (`rate_limited`), never on a cap.

The two calls the server answers by rendering a PDF
(`consentDocuments()->getInformationPdf()` and `manualConsents()->createConsentSheet()`)
take the client's `timeout` or **15 s**, whichever is larger, and accept their own
`'timeout' => ms` option.

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
  authorizes the consent-request endpoints; `attest` authorizes manual consents, the
  paper of a verbal consent, and revoke and erase of any company-recorded cell;
  `attest_verbal` (never granted by default) authorizes recording a telephone consent,
  reading its history, and revoking verbal cells only;
  `relationship` authorizes the relationship end/revert; either `check` or `issue`
  reads `GET /v1/catalog` and the consent documents (the information PDF also
  accepts `attest` and `attest_verbal`); `attest` also prints consent sheets;
  `retention` authorizes the retention rules, the catalogue cells and the purge and
  pass declarations; `inventory` authorizes the inventory declaration and its reads;
  `registry` authorizes one customer's identity, retention posture, dispositions and
  holds, addressed only by a `customerRef` you already hold (no list endpoint, by
  construction); `withdraw` (never granted by default) records a person's withdrawal
  on her behalf; `holds` (never granted by default) reads the feed of the holds in
  place. `identity()` returns whatever the server sends, so a scope added later can
  reach you at runtime.

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
