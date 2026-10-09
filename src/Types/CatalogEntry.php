<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A declared active catalog entry, for issuance discovery (GET /v1/catalog).
 *
 * `legalBasis` is the cell's DECLARED ground; its vocabulary depends on the tenant's
 * act, so read the regime ({@see DocumentCatalog::$regime}, or identity()->company)
 * before switching on it. `sensitive` is the company's own declaration that the cell
 * holds sensitive personal information. Both are null when the server did not send them.
 */
final class CatalogEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $purpose,
        public readonly ?string $description,
        public readonly ?string $legalBasis = null,
        public readonly ?bool $sensitive = null,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['id'] ?? null),
            Wire::str($wire['category'] ?? null),
            Wire::str($wire['purpose'] ?? null),
            Wire::nullableStr($wire['description'] ?? null),
            Wire::nullableStr($wire['legalBasis'] ?? null),
            array_key_exists('sensitive', $wire) ? Wire::bool($wire['sensitive']) : null,
        );
    }
}
