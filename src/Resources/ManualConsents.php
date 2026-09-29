<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\IdempotencyKey;
use Agreely\Sdk\Types\ClaimLink;
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
 */
final class ManualConsents
{
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
     * purpose already held by an active passkey-signed consent, or a record for the
     * same customer and document while a verbal consent awaits its paper (send that
     * paper with verbalConsents()->confirmWithPaper instead).
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
     *     evidence:array{pdfSha256:string,pdf?:string}
     * } $input
     * @param array{idempotencyKey?:string} $options
     */
    public function record(array $input, array $options = []): ManualConsentResult
    {
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

        $idempotencyKey = IdempotencyKey::resolve($options, 'manualConsents.record');

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/manual-consents',
            body: [
                'customerId' => $input['customerId'],
                'documentVersionId' => $input['documentVersionId'],
                'effectiveDate' => $input['effectiveDate'],
                'validUntil' => $input['validUntil'],
                'items' => $input['items'],
                'evidence' => $wireEvidence,
            ],
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
     * A customerId the company holds no record of is AgreelyNotFoundError (404) and
     * writes nothing; a customer whose relationship has ended is AgreelyConflictError
     * (409, code "conflict").
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
     * back is AgreelyConflictError (409): withdraw the confirming manual ref instead
     * (see verbalConsents()->get()).
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
     * AgreelyConflictError (409).
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
}
