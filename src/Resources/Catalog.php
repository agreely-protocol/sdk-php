<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\HostInput;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\Types\CatalogCells;
use Agreely\Sdk\Types\CatalogEntry;
use Agreely\Sdk\Types\DocumentCatalog;
use Agreely\Sdk\Types\Wire;

/**
 * The catalog resource: read-only discovery of the company's declared
 * (category, purpose) entries.
 *
 * TWO ENDPOINTS, TWO SCOPES. {@see Catalog::list()} serves the consent callers
 * ('check' OR 'issue') with the ACTIVE entries, for composing issuance.
 * {@see Catalog::listCells()} serves a host system ('retention') with EVERY cell,
 * archived ones included, and the retention rule that governs each.
 */
final class Catalog
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * The company's active declared catalog.
     *
     * 🔴 NEVER DECIDE CONSENT FROM A CACHED CATALOG. A cell listed here says what the
     * organisation declared, not what a person consented to: gate every use on a live
     * check() (which is never cached either).
     *
     * @return list<CatalogEntry>
     */
    public function list(): array
    {
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/catalog',
            idempotentRetry: true,
        ));
        return array_map(
            static fn (array $e): CatalogEntry => CatalogEntry::fromWire($e),
            Wire::objects($wire, 'catalog'),
        );
    }

    /**
     * The active cells of ONE published consent document (GET /v1/catalog?documentCode=),
     * with the tenant's regime and the document's current `documentVersionId`: one call
     * builds an intake form for that document AND names the version to record against.
     * Right for a single intake screen, where rendering the whole catalog would ask a
     * person about holdings the document never covered.
     *
     * Scope 'check' OR 'issue'. An unknown code and another tenant's code are the same
     * AgreelyNotFoundError.
     */
    public function forDocument(string $documentCode): DocumentCatalog
    {
        $code = HostInput::pathKey($documentCode, 'catalog.forDocument', 'documentCode');
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/catalog',
            query: ['documentCode' => $code],
            idempotentRetry: true,
        ));
        return DocumentCatalog::fromWire($wire);
    }

    /**
     * EVERY catalogue cell a host may hold data under (archived ones included, with
     * their status), each with the retention rule that governs it, plus the tenant's
     * regime because a cell's `legalBasis` vocabulary depends on it.
     *
     * Scope: 'retention', NOT 'check' or 'issue' like {@see Catalog::list()}: this is
     * the host-retention surface, read by a purge job rather than by a consent caller.
     *
     * 🔴 A cell whose `retentionRuleKey` is null is a GAP IN THE REGISTER, surfaced and
     * never filled: the host abstains from purging under it and never picks a duration
     * of its own. {@see CatalogCells::gaps()} collects them for the responsable.
     */
    public function listCells(): CatalogCells
    {
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/catalog/cells',
            idempotentRetry: true,
        ));
        return CatalogCells::fromWire($wire);
    }
}
