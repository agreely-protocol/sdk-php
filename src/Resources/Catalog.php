<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\Types\CatalogCells;
use Agreely\Sdk\Types\CatalogEntry;
use Agreely\Sdk\Types\Wire;

/**
 * The catalog resource — read-only discovery of the company's declared
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
