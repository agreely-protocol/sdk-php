# Changelog

All notable changes to `agreely/sdk` (PHP) are documented here. This project
adheres to [Semantic Versioning](https://semver.org/). Packagist reads the git
tag as the released version.

## Unreleased

### Added

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
