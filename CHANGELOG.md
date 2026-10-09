# Changelog

All notable changes to `agreely/sdk` (PHP) are documented here. This project
adheres to [Semantic Versioning](https://semver.org/). Packagist reads the git
tag as the released version.

## 0.5.0 - 2026-10-09

Covers the whole production /v1 API as deployed on 2026-10-09. Every route the API
tier serves now has a method, and the TypeScript twin `@agreely/sdk` 0.5.0 exposes the
same surface under the same method and type names. A result that extends a record in
TypeScript extends it here too, so its fields sit directly on it (`$placed->id`,
`$declared->agreelyIdentity`).

### Breaking

- **`manualConsents()->record()` takes a CLOSED input.** A member outside `customerId`,
  `documentVersionId`, `effectiveDate`, `validUntil`, `items`, `evidence`,
  `versionAttested` and `sensitiveExpressAttested` is refused before the call
  (`AgreelyConfigError`) instead of being dropped. A misspelt statutory attestation
  must fail loudly, not reach the server as "not attested".
- **A 429 keeps the code it was sent with, and only `rate_limited` is ever
  auto-retried.** Before, any 429 this client did not know read `rate_limited` and could
  be retried on a read with `maxRetries` set. The rule, the same in both SDKs: a known
  daily-cap code, or any 429 whose reason is `daily_cap`, raises `AgreelyDailyCapError`;
  `sweep_too_frequent` raises `AgreelySweepTooFrequentError`; any other code raises the
  base `AgreelyRateLimitError` keeping its code; none of them is retried.
- **Closed vocabularies gained values.** `Scope::ALL` adds `withdraw` and `holds`
  (`Scope::WITHDRAW`, `Scope::HOLDS`): code switching on it exhaustively must handle
  them.
- **`AgreelyUnavailableError` keeps the envelope's `code`.** A 5xx, or a status mapped
  to no other error (405, 410, an unfollowed 3xx), now carries the `code`, `field` and
  `reason` the server sent instead of the fixed `unavailable`; the class raised is
  unchanged.

### Added

- **`reason` and `field` on every API error.** `$e->reason` is the stable machine reason
  the server sends beside `code` on the manual, verbal, claim-link, consent-sheet and
  withdrawal routes; compare it, never the message. `ErrorReason` names the known
  values, `ErrorCode` the known codes (the registry, holds, dispositions and inventory
  carry their stable string in `code`), and `$e->hasReason()` compares one. An unknown
  future reason is readable as a plain string and never throws. `ErrorCode::DAILY_CAPS`
  lists the 24-hour cap codes.
- **`AgreelyDailyCapError`**, the rolling 24-hour caps, never auto-retried: the
  withdrawal cap (`withdrawal_daily_cap`, reason `daily_cap`), the hold caps
  (`hold_budget_exhausted`, `hold_release_cap_reached`), and `AgreelyVerbalDailyCapError`,
  now a subclass of it. `$e->code` says which cap.
- **`validUntil` and `revokedAt` on `CheckResult` and `BatchDecision`**: the end of the
  consent backing the answer and, on `revoked`, the withdrawal instant. Null when there
  is no consent record (the keys are absent from the wire). `validUntil` is an upper
  bound, never a cache lease.
- **`$agreely->consentDocuments()`**: `list()`, `get($code)` with the full published
  information (`ConsentDocumentSummary`, `ConsentDocumentDetail`, `ConsentDocumentItem`,
  `LocalizedText`, whose `text()` refuses a locale other than `fr` or `en`), and
  `getInformationPdf($documentVersionId, ['locale' => ..., 'timeout' => ...])`, the
  information document as PDF bytes (`InformationDocument`).
- **`$agreely->catalog()->forDocument($documentCode)`**: a `DocumentCatalog` with the
  regime, the `document` (`code`, `documentVersionId`) and its active cells in `catalog`. `CatalogEntry` gains
  `legalBasis` and `sensitive`, which the wire always carried.
- **`manualConsents()->createConsentSheet($customerRef, ...)`**: the signature sheet of a
  published version for one customer, minted with its claim and returned once
  (`ConsentSheet`). Its Idempotency-Key is a latch: a retry answers 409 `already_minted`.
- **`manualConsents()->record()` sends `versionAttested` and `sensitiveExpressAttested`**,
  each as JSON `true` only, and refuses a non-boolean.
- **`ManualConsentErasure::$gate`**: what /v1/check does after an erasure.
- **`$agreely->withdrawals()->record($customerRef, $consentRef, ...)`** (scope
  `withdraw`): a person's withdrawal of any consent ask, recorded on her behalf
  (`ConsentWithdrawal`, with `gate` and `alsoWithdrawn`; `WithdrawalChannel` names the
  channels). The one route that never answers the billing 402.
- **`$agreely->customers()`** (scope `registry`): `get()` (`CustomerRecord`) and
  `upsert()`, the registry identity as metadata. `upsert()` is a merge (absent
  untouched, null clears) and returns an `UpsertCustomerResult`, a `CustomerRecord` that
  says whether it `created` the record. A customerRef with a "/" or a "%" travels as one
  encoded segment; "." and ".." are refused before the call.
- **Per-customer retention on `$agreely->retention()`** (scope `registry`):
  `getCustomerRetention()` (the derived clock, the standing disposition and the holds),
  `declareDisposition()` (`DeclaredDisposition`, extending `RetentionDispositionRecord`),
  `placeHold()` and `releaseHold()` (`PlacedHold` and `ReleasedHold`, extending
  `RetentionHold`), with `agreelyIdentity` (`AgreelyIdentityOutcome`) on what a
  declaration or a release did to Agreely's own copy of the identity. `DispositionKind`,
  `HoldGround` and `RelationshipLifecycle` name the vocabularies. A misspelt option is
  refused rather than silently generating a fresh Idempotency-Key.
- **The holds feed on `$agreely->retention()`** (scope `holds`): `listHolds()` (one
  `RetentionHoldPage`), `holdPages()` (a Generator of pages) and `syncHolds()` (a
  `HoldsSync`: the mode, every `RetentionHoldFeedItem` and the cursor to persist). Both
  readers THROW rather than end on a partial feed: past `maxPages`, or on a last page
  with no cursor. An empty `changedSince` is a snapshot. A hold whose status is unknown
  or missing counts as in place (`isActive()` is false only on `released`).
- **`Identity::$company`** (name, sector, statute, public policy url) and
  `Identity::hasScope()`. `Scope` adds `WITHDRAW` and `HOLDS`.
- `RequestSpec::$timeoutMs`, a per-call budget. The two calls the server answers by
  rendering a PDF default to the client's `timeout` or 15 s, whichever is larger.

### Changed

- **The live contract suite covers the 0.5.0 surface** (`LiveSurfaceContractTest`): the
  check dates, the consent documents and their PDF, the registry upsert, the consent
  sheet latch, a withdrawal on behalf, and a hold placed, read through the feed and
  released, every customer reference carrying a "/" and a "%41". The revoke step reads
  the stack checkout from `AGREELY_APP_DIR`.
- **`Inventory::MAX_SETS` is 200** (was 50), as the server now accepts. A company may
  introduce at most 1000 new set keys in any rolling 30 days (422 `new_set_limit`).

### Documented

- The health probe: call `identity()` about once a minute from a single scheduler; on a
  401 or a 402, purge anything cached from Agreely and fail closed. Never cache
  `check()`, and never decide consent from a cached catalog.
- Telephone renewal of a paper consent in its last 30 days, with
  `renewal_ends_before_current`, `renewal_predates_current` and `predates_withdrawal`.
- A withdrawal attaches to the purpose; `citizen_consent_at_gate` on revoke and erase,
  and the withdrawal route as the way to end a consent the person signed online.
- The two consent-sheet rules: never send the claim link in the same envelope as the
  sheet, and never send the blank sheet's hash as `evidence.pdfSha256`.

## 0.4.0 - 2026-09-29

Aligns the client with the production /v1 API as deployed on 2026-09-29 (the verbal
tier, issue #100, and the informed-line rules). **A minor bump because it breaks**:
before 1.0 a breaking change moves the minor version. A status was removed, the
meaning of `approved` changed, and two closed vocabularies gained values that code
switching on them exhaustively must now handle.

### Breaking

- **`CheckStatus::SENSITIVE_REQUIRES_CONSENT` is removed.** The API stopped emitting
  `sensitive_requires_consent` on 2026-09-28: a sensitive cell now answers by its
  declared basis like any other (`necessity` + `basis` on a non-consent ground, `none`
  on `consent`). Code referencing the constant no longer compiles; delete that branch.
- **`approved` no longer covers an all-declined answer.** A consent request whose
  every consent ask was declined (only the informed lines acknowledged) reads the new
  `asks_declined` status, is never returned under `?status=approved`, and is terminal.
  `approved` now means at least one ask was accepted. `ConsentRequestStatus` names the
  vocabulary; `waitForSettlement()` returns on `asks_declined`.
- **Closed vocabularies gained values.** `assurance` adds `company_documented`, the
  new `tier` field reads `full | manual | verbal`, `CheckStatus::ALL` adds
  `requires_depersonalization` and `basis_not_in_regime` (both already on the wire),
  `CheckBasis::ALL` adds the seven public-body (A-2.1) bases, and
  `ReceiptVerification::$receiptType` adds `company_documented`.

### Added

- **`$agreely->verbalConsents()`**: `record()` (POST /v1/verbal-consents, scope
  `attest_verbal`), `confirmWithPaper()` (the signed paper, scope `attest`) and
  `get()` (the history, either scope), with `VerbalConsentResult`,
  `VerbalPaperResult`, `VerbalConsentHistory`, `VerbalPurpose` (whose `answer` may be
  `informed`) and `RepresentativeCapacity` (including `titulaire_autorite_parentale`
  and `tuteur_mineur` for a minor under 14). `isMinor`, `paperExpected` and
  `sensitiveExpressAttested` are sent only as JSON `true`. Every answer must be the
  string `yes` or `no`; anything else is refused before the call.
- **`tier` on `CheckResult` and `BatchDecision`**, and the `Assurance` and
  `ConsentTier` vocabularies, with `ConsentTier::atLeast()`, which never accepts an
  unknown or null tier. An acknowledged informed line carries no assurance and no
  tier, even once withdrawn.
- **`Scope::ATTEST_VERBAL`.**
- **`ManualConsentResult::$acknowledged` and `$asksDeclined`**: the informed lines the
  server added as acknowledgements, and whether no ask was consented. `items` may be
  empty.
- **`ManualConsentRevocation::$gate`** (`denied | superseded | unchanged`): what
  /v1/check does now for that purpose. A `superseded` withdrawal leaves a later
  consent backing the gate.
- **`AgreelyVerbalDailyCapError`** (429 `verbal_daily_cap`, a subclass of
  `AgreelyRateLimitError`), never auto-retried.
- **`AgreelyConflictError::isStateConflict()` and `isRetryable()`**: a 409 `conflict`
  (a covered purpose, an ended relationship, a paper already recorded, a paper while a
  verbal consent awaits its own) is no longer documented as something to retry.
- **The host-retention resource** (`$agreely->retention()`, scope `retention`), built
  from the committed `openapi.yaml`: `listRules` (with `changedSince`), `getRule`,
  `declarePurge`, `declareSweep`, and `rulesForPurge`, which does the incremental
  merge and decides the outage for a purge job. `$agreely->catalog()->listCells()`
  reads every catalogue cell with the rule that governs it; a null `retentionRuleKey`
  is the register's own gap, surfaced and never filled.
- **The host inventory resource** (`$agreely->inventory()`, scope `inventory`):
  `replaceCategories`, `listCategories`, `getStatement`. `/v1/inventory/*` is NOT in
  the committed `openapi.yaml` as of 2026-09-25, so this resource is built from the
  shipped `InventoryController` and its shapes are asserted against an implementation
  rather than a ratified contract.
- **`RetentionAction` and `PurgeMethod` are two separate classes**, so a rule's own
  `destroy` / `anonymize` cannot be mistaken for a declaration's `destroyed` /
  `anonymized`. Sending the rule's word is refused client-side and the message names
  the word that was meant; `PurgeMethod::forRule()` derives one from the other. There
  is no `aggregated`: aggregation is a technique recorded on an anonymisation
  process, not a third disposition.
- **`Agreely\Sdk\Types\Scope`**, the full scope vocabulary including `registry`,
  which no resource wraps.
- **`AgreelySweepTooFrequentError`** (429 `sweep_too_frequent`, the per-(rule,
  hostSystem) 15-minute floor, a subclass of `AgreelyRateLimitError`) and
  **`AgreelyConflictError`** (409 `retry`: retry with the SAME Idempotency-Key).

### Fixed

- **A verbal receipt was reported as a tampered citizen receipt.** `verifyReceipt()`
  read a `VerbalConsentReceipt` as a citizen receipt, found no passkey assertion and
  answered `citizenAssertion: "fail"`, `overall: "failed"`: a false accusation of
  tampering on genuine evidence. It is now `receiptType: "company_documented"`: the
  company signature is checked like a paper receipt's, the citizen assertion is
  `unsupported`, and `overall` is at most `partial` (never `verified`), because the
  signature proves only that the organisation documented a telephone consent.
- **Stale idempotency documentation.** On the manual and verbal endpoints the server
  now binds the Idempotency-Key to the endpoint and to the request body. A key you
  pass is checked (1 to 255 printable ASCII characters) before the call.
- **`consentRequests()->list()`** refuses a filter or cursor sent as a list before the
  call; the server answers 400.

### Documented

- `validUntil` on every consent write: a plain date means through the end of that
  calendar day in the organisation's timezone, an instant needs an offset, a relative
  phrase is refused, and ten years after the start is the ceiling (an Agreely product
  rule, not a statutory limit).
- Paper consents: a notice-only document, an empty or non-PDF file, the SHA-256 of
  zero bytes and an escrowed PDF that does not match its hash are refused (422); a
  paper for a customer and document while a verbal consent awaits its paper is a 409.
- Claim links: an unknown customer is a 404, an ended relationship a 409.

### Changed

- **413 maps to `AgreelyValidationError`**, not `AgreelyUnavailableError`. It fell
  into the transport's default 5xx branch, which made an over-large body read as a
  transient outage and therefore retryable, on a body that can never be accepted.
- **`AgreelySweepTooFrequentError` is never auto-retried**, whatever the call and
  whatever `maxRetries` says. Waiting out the 15-minute floor and re-sending records
  a SECOND pass for one run.
- `HttpClient::send()` accepts `PUT` (the inventory declaration). A custom client
  that whitelists verbs must admit it.

### Refused before the request leaves

Each of these is a sure refusal at the server, and every one of them is now an
`AgreelyConfigError` with no wire call: an `Idempotency-Key` placed in a declaration
body instead of the `$options` argument (the input shapes are closed, so it cannot
reach the body); an `anonymized` purge with no process key, or a `destroyed` one with
one; `recordsAffected` below 1 (a pass that found nothing is a sweep) or above
1,000,000,000; more than 1000 `references` or more references than `recordsAffected`;
a `sweptAt` older than 24 hours, which is the case nobody guesses from a remote 422
(a queued retry from yesterday, a cron on a skewed clock); a pod-name or id-shaped
`hostSystem`, which burns one of the 10 host systems allowed per 30 days per deploy;
a naive timestamp with no offset; and an empty `categories` list on
`replaceCategories`, which would otherwise read as « withdraw everything ».

### Fail-closed, in the other direction

For a consent check, failing closed means denying. For a purge job it means NOT
PURGING: missing a SHORTENED rule keeps data a little too long, while missing a
LENGTHENED one (a legal hold, an investigation) destroys what had to be kept, and
that does not repair. So `rulesForPurge` ABSTAINS on an outage, `RulesForPurge::rules()`
throws on an abstain rather than handing back an empty list that would read as
"nothing to purge", and purging on a stored snapshot is opt-in, bounded by
`maxSnapshotAge` and audited through a mandatory `onDegrade`. Anything that is not an
outage (401/403, 402, 422, 429) throws, so a key or billing problem cannot pass as a
skipped night.

## 0.3.0 - 2026-08-22

### Fixed

- **The pinned Base mainnet registry address was DEAD.** `ReceiptVerifier` shipped
  `0x1E31...cF8B`, a superseded AgreelyRegistry. That contract is still deployed
  and still answers `eth_getLogs`, it simply holds no anchors, so `verifyReceipt`
  with an `rpcUrl` and no explicit `registryAddress` reported
  `documentAnchor: "fail"` on every mainnet receipt: a confident FALSE ACCUSATION
  OF TAMPERING on valid evidence. The constant is now the live registry,
  `0x23577fafFa306375028D33a559D0F95Ced9424DB` (deploy block 48889369, EIP-55
  checksummed). Two tests pin it, one of them reading the address back off the
  composed JSON-RPC payload rather than off a second copy of the constant. Anyone
  on 0.2.0 who verifies mainnet anchors should upgrade.
- **Both default DID resolution hosts pointed at tiers that do not serve the
  route.** The company `did:web` host defaulted to the apex `agreely.ca`, which is
  the marketing site and 404s on `/c/{slug}/did.json`; that document is served by
  the Agreely WEB tier, so the default is now `app.agreely.ca`. Worse, the citizen
  resolver base defaulted to `api.agreely.ca`, which does not route `GET /did/{did}`
  at all (that route is MODE=CITIZEN), so **every citizen receipt verified with the
  default resolver reported `citizenAssertion: "unavailable"` and
  `overall: "unavailable"`**: never a false "verified", but never a real verification
  either. The default is now `https://my.agreely.ca`. Verified against production:
  `app.agreely.ca/c/ophelios/did.json` returns 200 while the apex returns 404, and
  `my.agreely.ca/did/{did}` routes while `api.agreely.ca` returns 404.

  Company DIDs minted before this change read `did:web:agreely.ca:c:*` and will
  NEVER resolve, including in receipts already issued. That is not a regression this
  release introduces: those DIDs never resolved against any spec-compliant resolver.
  No compatibility shim and no fallback host is provided deliberately: a verifier
  that silently retries a second host hides which identity actually signed.
- **Removed every reference to a `receipts/verify` endpoint, which does not
  exist.** Two of them were in the human-readable `reason` notes returned to the
  person verifying a receipt, telling them to go use a server path that has never
  been built. The LIMITATION they describe is real and unchanged: a citizen
  receipt's company half signed the OFFER, which the receipt omits for
  unlinkability, and the salted cell labels cannot be re-derived offline. The
  REMEDY was wrong. The notes now say so plainly and point at the thing that does
  exist (the self-contained verification bundle Agreely issues with the receipt).
  Verdict semantics are untouched: `unsupported` / `partial` / `unavailable` mean
  exactly what they did.
- **Removed a false idempotency caveat on `manualConsents()->record`.** It warned
  that `Idempotency-Key` was "NOT server-honored yet" and that a retry "can create
  a DUPLICATE". The server has honoured it since 2026-07-01. The real behaviour is
  documented instead, including the one genuine caveat: the replay is keyed on
  (company, key) alone, not on the request body and not on the endpoint.

### Added

- `basis` on `CheckResult` and `BatchDecision`. `openapi.yaml` documents it and the
  API returns it, but `fromWire` was dropping it, so callers could not see WHY a
  `necessity` allow was granted. Both types also gain `isNecessity()`.
- `CheckStatus` and `CheckBasis`: the complete, documented vocabularies. The status
  list was missing `necessity` and `sensitive_requires_consent` (shipped
  2026-07-28) entirely. Note that `erased` is listed by `openapi.yaml` but is NOT
  currently emitted: erasure crypto-shreds the enforcement record, so an erased
  cell reads back as `deny` / `none`.
- `Agreely::BATCH_CAP` (500) and `Agreely::RATE_LIMIT_PER_MINUTE` (120, per
  COMPANY, not per key). Neither limit was documented anywhere in the SDK.

### Changed

- `checkBatch()` and `checkFields()` now throw `AgreelyConfigError` for an over-cap
  request BEFORE the wire call, instead of spending a request on a 422 that decides
  nothing. This matters most for `checkFields`, which builds a `refs x fields`
  product: 100 rows x 6 fields is 600 cells, over the cap, on an ordinary listing
  page. The error names both multiplicands and the safe page size.
- Documented that the 800ms default `timeout` is sized for a SAME-REGION call. The
  SDK fails closed, so an under-set timeout does not produce a slow answer, it
  produces a spurious DENY. The default is unchanged; internet-facing callers
  should raise it (8000ms with `maxRetries => 1` is a tested pairing).
- Documented that `relationships()->end()` throws `AgreelyNotFoundError` for a
  customer who has no consent history, which includes a customer served only on
  declared-necessity cells even though `check()` allows for that same ref.

## 0.2.0

### Added

- `consentRequests()->list([...])` now accepts a `customerId` filter (the
  company's own subject reference) and a `limit` page size (server default 50,
  max 100), alongside the existing `status` and `cursor`. Returns the same
  `ConsentRequestPage` (`->items`, `->nextCursor`); metadata only, newest first,
  tenant-scoped by the API key.
- `consentRequests()->hasPending($customerId, $documentCode = null)`: a dedup
  helper that reports whether an OPEN (still-pending) consent request already
  exists for a customer, optionally narrowed to a `$documentCode`. Use it before
  `create()` to avoid re-issuing (and re-emailing). A blank `$customerId` throws
  `AgreelyConfigError` before any wire call. This is a metadata convenience, not
  a compliance decision.
- `ConsentRequestRecord` now surfaces `->customerId` and `->documentCode`.
- `customerId` and `limit` also flow through the auto-paginating `iterate()` /
  `collect()`.

## 0.1.1

- Surface HTTP 402 as the typed `AgreelyBillingInactiveError`
  (code `billing_inactive`).
