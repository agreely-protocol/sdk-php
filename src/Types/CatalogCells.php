<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The body of GET /v1/catalog/cells: the tenant's regime, and every cell (archived
 * ones included) with the rule that governs it.
 *
 * The regime is here because a cell's `legalBasis` vocabulary depends on it. Read it
 * before switching on a basis.
 */
final class CatalogCells
{
    /** @param list<CatalogCell> $cells */
    public function __construct(
        public readonly ?Regime $regime,
        public readonly array $cells,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Regime::fromWireMember($wire, 'regime'),
            CatalogCell::listFromWire(Wire::objects($wire, 'cells')),
        );
    }

    /**
     * The cells with NO retention rule: the register's own gaps, which a host must
     * abstain from purging under. Surface them to the responsable rather than
     * choosing a duration for them.
     *
     * @return list<CatalogCell>
     */
    public function gaps(): array
    {
        return array_values(array_filter($this->cells, static fn (CatalogCell $c): bool => !$c->hasRule()));
    }
}
