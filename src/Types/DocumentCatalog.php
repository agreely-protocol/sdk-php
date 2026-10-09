<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * GET /v1/catalog narrowed to ONE published consent document: the tenant's regime, the
 * document it was narrowed to (its stable `documentCode` and the `documentVersionId`
 * POST /v1/manual-consents requires), and that document's ACTIVE cells. One call serves
 * both building an intake form and recording the consent it collects.
 */
final class DocumentCatalog
{
    /** @param list<CatalogEntry> $entries */
    public function __construct(
        public readonly ?Regime $regime,
        public readonly string $documentCode,
        public readonly string $documentVersionId,
        public readonly array $entries,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $document = Wire::object($wire, 'document') ?? [];
        return new self(
            Regime::fromWireMember($wire, 'regime'),
            Wire::str($document['code'] ?? null),
            Wire::str($document['documentVersionId'] ?? null),
            array_map(CatalogEntry::fromWire(...), Wire::objects($wire, 'catalog')),
        );
    }
}
