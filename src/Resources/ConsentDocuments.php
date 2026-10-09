<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\Errors\AgreelyUnavailableError;
use Agreely\Sdk\HostInput;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\Types\ConsentDocument;
use Agreely\Sdk\Types\ConsentDocumentDetail;
use Agreely\Sdk\Types\InformationDocument;
use Agreely\Sdk\Types\Wire;

/**
 * The organisation's PUBLISHED consent documents, read-only (scope 'check' or 'issue';
 * the information PDF also accepts 'attest' and 'attest_verbal'). Authoring and
 * publishing stay in Agreely: this resource never mutates a document.
 *
 * It is where a `documentVersionId` comes from: every consent write
 * (manualConsents()->record(), verbalConsents()->record(), createConsentSheet()) takes
 * one. Pin a document by its stable `code` and resolve the current version through it.
 */
final class ConsentDocuments
{
    /**
     * The least time budget of a call the server answers by rendering a PDF (this one,
     * and manualConsents()->createConsentSheet()), in milliseconds.
     */
    public const DOCUMENT_TIMEOUT_MS = 15_000;

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Every published document, each with the active cells its current version groups.
     *
     * @return list<ConsentDocument>
     */
    public function list(): array
    {
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/consent-documents',
            idempotentRetry: true,
        ));
        return array_map(ConsentDocument::fromWire(...), Wire::objects($wire, 'documents'));
    }

    /**
     * ONE published document with its full published information, resolved by its STABLE
     * code (matched exactly, then lowercased with accents folded). An unknown code and
     * another tenant's code are the same AgreelyNotFoundError.
     */
    public function get(string $code): ConsentDocumentDetail
    {
        $key = HostInput::pathKey($code, 'consentDocuments.get', 'code');
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/consent-documents/' . rawurlencode($key),
            idempotentRetry: true,
        ));
        return ConsentDocumentDetail::fromWire(Wire::object($wire, 'document') ?? []);
    }

    /**
     * THE INFORMATION DOCUMENT of one version, as PDF BYTES to print and hand to the
     * person, with the signature sheet or on its own
     * (GET /v1/consent-documents/versions/{documentVersionId}/pdf). Scope 'check',
     * 'issue', 'attest' or 'attest_verbal'.
     *
     * By VERSION id, never by code: the version a consent is recorded against is the one
     * whose text the person must be given. A version superseded since is served too, and
     * says so; a draft never is (an unknown, foreign or draft id is the same
     * AgreelyNotFoundError, and an id that is not a uuid is refused before the call).
     *
     * Options:
     *   locale   "fr" (the default) or "en". An English copy of a version with a section
     *            missing its English text is AgreelyValidationError code
     *            english_text_missing: request the French one.
     *   timeout  this call's budget in ms. Defaults to the client's `timeout` or 15000,
     *            whichever is larger: the server renders the PDF, which an 800 ms budget
     *            sized for the consent check would cut off.
     *
     * The bytes are not frozen (the letterhead follows the organisation's logo), so keep
     * your own copy keyed on (documentVersionId, locale) if you need one. Fetching it
     * records nothing: it is not evidence that anyone was informed. A read; safe to
     * auto-retry on a transient outage. A 200 whose body is not a PDF (a proxy's page,
     * say) is AgreelyUnavailableError, never bytes you would print for a person.
     *
     * @param array{locale?:string,timeout?:int} $options
     */
    public function getInformationPdf(string $documentVersionId, array $options = []): InformationDocument
    {
        $label = 'consentDocuments.getInformationPdf';
        HostInput::closed($options, ['locale', 'timeout'], $label);
        $id = HostInput::uuid($documentVersionId, "{$label}: documentVersionId");
        $locale = HostInput::locale($options['locale'] ?? 'fr', "{$label}: locale");
        $res = $this->transport->requestRaw(new RequestSpec(
            method: 'GET',
            path: '/v1/consent-documents/versions/' . rawurlencode($id) . '/pdf',
            query: ['locale' => $locale],
            headers: ['Accept' => 'application/pdf, application/json'],
            idempotentRetry: true,
            timeoutMs: HostInput::renderBudget($options, $this->transport->timeoutMs(), self::DOCUMENT_TIMEOUT_MS, $label),
        ));
        if (!str_starts_with($res->body, '%PDF-')) {
            throw new AgreelyUnavailableError(
                "{$label}: the answer was not a PDF (content-type " . ($res->header('content-type') ?? 'none') . ').',
                $res->status,
                false,
            );
        }
        return new InformationDocument(
            $res->body,
            InformationDocument::filenameFrom($res->header('content-disposition')),
            $res->header('content-type') ?? 'application/pdf',
        );
    }
}
