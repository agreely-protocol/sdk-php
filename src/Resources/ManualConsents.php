<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\HostInput;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\IdempotencyKey;
use Agreely\Sdk\Types\ClaimLink;
use Agreely\Sdk\Types\ConsentSheet;
use Agreely\Sdk\Types\ManualConsentErasure;
use Agreely\Sdk\Types\ManualConsentResult;
use Agreely\Sdk\Types\ManualConsentRevocation;

/**
 * The manual / offline (company-attested) consent resource (scope: 'attest'). The
 * company records a consent it gathered out of band and attests to it under its
 * own name; the resulting enforcement records carry assurance "company_attested"
 * (tier "manual"). revoke() also withdraws VERBAL cells (scope 'attest' or
 * 'attest_verbal'); erase() needs 'attest'.
 * Keyed throughout on the protocol consentRef (0x-hex), never an internal uuid.
 *
 * Every refusal carries a stable `reason` on the thrown error ({@see \Agreely\Sdk\Errors\ErrorReason}):
 * branch on it, never on the message.
 */
final class ManualConsents
{
    /** The members a consent-sheet request accepts, and no others. */
    private const SHEET_MEMBERS = ['documentVersionId', 'locale'];

    /** The members a paper record accepts, and no others. */
    private const RECORD_MEMBERS = [
        'customerId', 'documentVersionId', 'effectiveDate', 'validUntil', 'items', 'evidence',
        'versionAttested', 'sensitiveExpressAttested',
    ];

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Record a company-attested consent (the signed paper sheet). Evidence ALWAYS
     * carries the pdfSha256 commitment ("0x" + 64 hex); the pdf bytes (base64) are
     * uploaded only when explicitly provided. NEVER auto-retried (it mutates).
     *
     * `items` names the consent ASKS ticked on the sheet, as catalog ids and/or raw
     * {category, purpose} pairs resolved server-side, and MAY BE EMPTY (every ask
     * answered "no"). The server adds every line the document gives for information
     * as an acknowledgement (never a consent) and ignores one named in `items`; the
     * result reports them in `acknowledged` and says `asksDeclined` when no ask was
     * consented.
     *
     * Refused with 422 (AgreelyValidationError): a document that asks no consent (a
     * collection notice); an empty upload or the SHA-256 of zero bytes; an upload that
     * is not a PDF; an escrowed PDF that does not hash to pdfSha256; a validUntil that
     * is a relative phrase or more than ten years after the effective date (an Agreely
     * product rule, not a statutory limit). A plain-date validUntil means through the
     * END of that calendar day in the tenant's timezone; an instant needs an offset.
     *
     * Refused with 409 (AgreelyConflictError, code "conflict", do not retry): a
     * purpose already held by an active passkey-signed consent (reason
     * stronger_consent_active), a record for the same customer and document while a
     * verbal consent awaits its paper (verbal_awaits_paper: send that paper with
     * verbalConsents()->confirmWithPaper instead), an ended relationship
     * (relationship_ended), a paper signed before a withdrawal recorded for the same
     * purpose (predates_withdrawal), or a new sheet over a consent still in force that
     * would end before it (renewal_ends_before_current). An effectiveDate given as an
     * instant in the future by any amount is a 422 (effective_date_in_future).
     *
     * TWO ATTESTATIONS, each sent only as JSON true (anything but a boolean is refused
     * before the call, and false is the same as leaving it out):
     *   - versionAttested: REQUIRED true when the paper was signed BEFORE the chosen
     *     version was published in Agreely. The organisation attests that the signed
     *     document asked consent for the same purposes, and gave the same information,
     *     as that version (422 version_attestation_required otherwise).
     *   - sensitiveExpressAttested: REQUIRED true when a ticked purpose is sensitive and
     *     rests on the consent basis: the organisation attests that the consent was
     *     express (422 sensitive_express_required otherwise).
     *
     * IDEMPOTENT on retry: an Idempotency-Key is auto-generated per call (override via
     * $options['idempotencyKey'], 1 to 255 printable ASCII characters, checked before
     * the call). The server BINDS the key to this endpoint and to the request body: a
     * retry with the same key and the same body replays the original 201 (same
     * consentId, same consentRefs) and records nothing new, while the same key with a
     * different body is a new request.
     *
     * @param array{
     *     customerId:string,
     *     documentVersionId:string,
     *     effectiveDate:string,
     *     validUntil:string,
     *     items:list<string|array{category:string,purpose:string}>,
     *     evidence:array{pdfSha256:string,pdf?:string},
     *     versionAttested?:bool,
     *     sensitiveExpressAttested?:bool
     * } $input
     * @param array{idempotencyKey?:string} $options
     */
    public function record(array $input, array $options = []): ManualConsentResult
    {
        HostInput::closed($input, self::RECORD_MEMBERS, 'manualConsents.record');
        foreach (['customerId', 'documentVersionId', 'effectiveDate', 'validUntil', 'items', 'evidence'] as $required) {
            if (!isset($input[$required])) {
                throw new AgreelyConfigError("manualConsents.record requires \"{$required}\".");
            }
        }
        $evidence = $input['evidence'];
        if (!is_array($evidence) || !isset($evidence['pdfSha256'])) {
            throw new AgreelyConfigError('manualConsents.record requires "evidence.pdfSha256".');
        }

        $wireEvidence = ['pdfSha256' => $evidence['pdfSha256']];
        if (isset($evidence['pdf'])) {
            $wireEvidence['pdf'] = $evidence['pdf'];
        }

        HostInput::closed($options, ['idempotencyKey'], 'manualConsents.record');
        $idempotencyKey = IdempotencyKey::resolve($options, 'manualConsents.record');

        $body = [
            'customerId' => $input['customerId'],
            'documentVersionId' => $input['documentVersionId'],
            'effectiveDate' => $input['effectiveDate'],
            'validUntil' => $input['validUntil'],
            'items' => $input['items'],
            'evidence' => $wireEvidence,
        ];
        // A statutory attestation is a JSON boolean and nothing else: the server refuses "true" or 1 with a 422,
        // so it is refused here, where the mistake is. Only true is sent; false is the server's default.
        foreach (['versionAttested', 'sensitiveExpressAttested'] as $flag) {
            if (!array_key_exists($flag, $input)) {
                continue;
            }
            if (!is_bool($input[$flag])) {
                throw new AgreelyConfigError("manualConsents.record: \"{$flag}\" must be a boolean.");
            }
            if ($input[$flag]) {
                $body[$flag] = true;
            }
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/manual-consents',
            body: $body,
            headers: ['Idempotency-Key' => $idempotencyKey],
            idempotentRetry: false,
        ));

        return ManualConsentResult::fromWire($wire);
    }

    /**
     * Create a claim link the company hands to the subject so they can self-claim
     * the recorded attestation. Mutates (mints a token); never auto-retried. Minting
     * retires any link still live for the same customer.
     *
     * A customerId the company holds no record of is AgreelyNotFoundError (404, reason
     * unknown_customer) and writes nothing; a customer whose relationship has ended is
     * AgreelyConflictError (409, code "conflict", reason relationship_ended).
     *
     * For a paper the person signs, prefer {@see ManualConsents::createConsentSheet()}:
     * it prints the sheet and mints this link in one call, bound to the reference
     * printed on the sheet.
     *
     * @param array{customerId:string,reference?:string} $input
     */
    public function createClaimLink(array $input): ClaimLink
    {
        if (!isset($input['customerId'])) {
            throw new AgreelyConfigError('manualConsents.createClaimLink requires "customerId".');
        }

        $body = ['customerId' => $input['customerId']];
        if (isset($input['reference'])) {
            $body['reference'] = $input['reference'];
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/manual-consents/claim-links',
            body: $body,
            idempotentRetry: false,
        ));

        return ClaimLink::fromWire($wire);
    }

    /**
     * Withdraw a company-recorded consent cell (manual OR verbal) by its protocol
     * consentRef (0x-hex). Idempotent server-side; never auto-retried.
     *
     * Scope 'attest' reaches manual and verbal cells; a key holding only
     * 'attest_verbal' reaches VERBAL cells only (any other ref is the same 404 as one
     * that does not exist). Read `gate` on the result: "superseded" means a later
     * consent still backs the gate. Withdrawing the manual cell that confirmed a
     * verbal one withdraws that verbal cell too. A verbal ref whose paper already came
     * back is AgreelyConflictError (409, reason superseded_by_paper): withdraw the
     * confirming manual ref instead (see verbalConsents()->get()).
     *
     * A withdrawal attaches to the PURPOSE: every other consent still running for it (a
     * renewal and the paper it renewed, a newer sheet and the one it replaced) is
     * withdrawn at the same instant. When the person later signed the same purpose
     * online with her passkey, that signed consent holds the gate and this route never
     * ends it: AgreelyConflictError (409, reason citizen_consent_at_gate), nothing
     * written. Record her withdrawal with withdrawals()->record() (scope 'withdraw')
     * instead, which withdraws both at one instant.
     *
     * @param array{reason?:string} $input
     */
    public function revoke(string $consentRef, array $input = []): ManualConsentRevocation
    {
        $body = [];
        if (isset($input['reason'])) {
            $body['reason'] = $input['reason'];
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/manual-consents/' . rawurlencode($consentRef) . '/revoke',
            body: $body === [] ? null : $body,
            idempotentRetry: false,
        ));

        return ManualConsentRevocation::fromWire($wire);
    }

    /**
     * Erase (crypto-shred) a company-recorded consent cell by its protocol consentRef
     * (0x-hex). Scope 'attest' only: an erasure cannot be undone. Idempotent
     * server-side; never auto-retried. Erasing the manual cell that confirmed a verbal
     * consent also erases that verbal cell; a verbal ref whose paper came back is
     * AgreelyConflictError (409, reason superseded_by_paper). Erasing a consent still in
     * force WITHDRAWS (never erases) every other consent running for the same purpose,
     * and `gate` on the result says what /v1/check does now. While a consent the person
     * signed online holds the purpose it is a 409 (reason citizen_consent_at_gate) and
     * nothing is written: record her withdrawal with withdrawals()->record() first,
     * then erase.
     *
     * @param array{reason?:string} $input
     */
    public function erase(string $consentRef, array $input = []): ManualConsentErasure
    {
        $body = [];
        if (isset($input['reason'])) {
            $body['reason'] = $input['reason'];
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/manual-consents/' . rawurlencode($consentRef) . '/erase',
            body: $body === [] ? null : $body,
            idempotentRetry: false,
        ));

        return ManualConsentErasure::fromWire($wire);
    }

    /**
     * Print the SIGNATURE SHEET of one published version for one customer, minted with
     * the claim the person may later redeem (POST /v1/customers/{customerRef}/consent-sheets,
     * scope 'attest'). The same sheet the web paper flow prints; it never names the person.
     *
     * PRINTING IS A MINT. `printedReference` (printed on the sheet) is the second factor
     * of the claim link minted in the same call. It and the claim token are returned ONCE,
     * here, and no call can recover them. A new sheet retires any claim still live for
     * this customer, so the reference on a previous sheet stops working.
     *
     * 🔴 TWO RULES THE HOST MUST KEEP:
     *   - NEVER send the claim link in the same envelope or message as the sheet. The
     *     printed reference only defends a link that travels separately; both together
     *     make it decorative.
     *   - NEVER send the hash of this BLANK sheet as `evidence.pdfSha256` to
     *     {@see ManualConsents::record()}. The evidence is the SIGNED sheet, scanned or
     *     photographed once it comes back; that is why the response carries no hash.
     *
     * `documentVersionId` is required (a PUBLISHED version of this organisation's
     * documents, 422 reason invalid_document otherwise); `locale` is "fr" (the default)
     * or "en". A version that asks no consent has nothing to sign (422 code
     * no_consent_ask, reason notice_only). An unknown customer is AgreelyNotFoundError
     * (reason unknown_customer, the claim-link answer); an ended relationship is
     * AgreelyConflictError (reason relationship_ended).
     *
     * The Idempotency-Key is a LATCH, never a replay of the answer: a retry with the same
     * key and body mints nothing and answers AgreelyConflictError (code and reason
     * already_minted), because the reference and the token are never stored to be
     * replayed. A key is generated per call unless you pass one. NEVER auto-retried.
     * The server renders the PDF, so the call's budget is the `timeout` option, else the
     * client's `timeout` or 15000 ms, whichever is larger.
     *
     * @param array{documentVersionId:string,locale?:string} $input
     * @param array{idempotencyKey?:string,timeout?:int} $options
     */
    public function createConsentSheet(string $customerRef, array $input, array $options = []): ConsentSheet
    {
        $label = 'manualConsents.createConsentSheet';
        $ref = HostInput::customerRef($customerRef, $label);
        HostInput::closed($input, self::SHEET_MEMBERS, $label);
        $versionId = $input['documentVersionId'] ?? null;
        if (!is_string($versionId) || trim($versionId) === '') {
            throw new AgreelyConfigError("{$label} requires \"documentVersionId\".");
        }
        HostInput::closed($options, ['idempotencyKey', 'timeout'], $label);

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/customers/' . rawurlencode($ref) . '/consent-sheets',
            body: [
                'documentVersionId' => trim($versionId),
                'locale' => HostInput::locale($input['locale'] ?? 'fr', "{$label}: locale"),
            ],
            headers: ['Idempotency-Key' => IdempotencyKey::resolve($options, $label)],
            idempotentRetry: false,
            timeoutMs: HostInput::renderBudget(
                $options,
                $this->transport->timeoutMs(),
                ConsentDocuments::DOCUMENT_TIMEOUT_MS,
                $label,
            ),
        ));

        return ConsentSheet::fromWire($wire);
    }
}
