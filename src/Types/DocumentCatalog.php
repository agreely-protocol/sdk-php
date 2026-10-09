<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * GET /v1/catalog narrowed to ONE published consent document: the tenant's regime, the
 * document it was narrowed to (`document['code']`, its stable code, and
 * `document['documentVersionId']`, what the consent writes take), and that document's
 * ACTIVE cells in `catalog`. One call serves both building an intake form and recording
 * the consent it collects.
 */
final class DocumentCatalog
{
    /**
     * @param array{code:string,documentVersionId:string} $document
     * @param list<CatalogEntry> $catalog
     */
    public function __construct(
        public readonly ?Regime $regime,
        public readonly array $document,
        public readonly array $catalog,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $document = Wire::object($wire, 'document') ?? [];
        return new self(
            Regime::fromWireMember($wire, 'regime'),
            [
                'code' => Wire::str($document['code'] ?? null),
                'documentVersionId' => Wire::str($document['documentVersionId'] ?? null),
            ],
            array_map(CatalogEntry::fromWire(...), Wire::objects($wire, 'catalog')),
        );
    }
}
