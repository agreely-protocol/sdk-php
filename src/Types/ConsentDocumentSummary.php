<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A PUBLISHED consent document, as GET /v1/consent-documents lists it: what it is and
 * which cells its current version groups, with no disclosure prose.
 *
 *   code               the STABLE per-company slug shared by every version. Pin THIS,
 *                      not a uuid: it keeps resolving to the version currently published.
 *   documentVersionId  the currently published version: exactly what
 *                      manualConsents()->record(), verbalConsents()->record() and
 *                      createConsentSheet() take. It goes stale at the next publication.
 *   effectiveDate      a calendar DATE; `publishedAt` an RFC 3339 UTC instant or null
 *
 * Not final: {@see ConsentDocumentDetail} extends it, as the TypeScript twin's interface does.
 */
class ConsentDocumentSummary
{
    /** @param list<ConsentDocumentItem> $items */
    public function __construct(
        public readonly string $code,
        public readonly string $documentVersionId,
        public readonly string $version,
        public readonly string $name,
        public readonly ?string $nameEn,
        public readonly string $effectiveDate,
        public readonly ?string $publishedAt,
        public readonly array $items,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(...self::wireArgs($wire));
    }

    /**
     * The summary members, read off the wire, as the named arguments of the constructor,
     * so a subclass reads them the same way.
     *
     * @param array<string,mixed> $wire
     * @return array{code:string,documentVersionId:string,version:string,name:string,nameEn:?string,effectiveDate:string,publishedAt:?string,items:list<ConsentDocumentItem>}
     */
    protected static function wireArgs(array $wire): array
    {
        return [
            'code' => Wire::str($wire['code'] ?? null),
            'documentVersionId' => Wire::str($wire['documentVersionId'] ?? null),
            'version' => Wire::str($wire['version'] ?? null),
            'name' => Wire::str($wire['name'] ?? null),
            'nameEn' => Wire::nullableStr($wire['nameEn'] ?? null),
            'effectiveDate' => Wire::str($wire['effectiveDate'] ?? null),
            'publishedAt' => Wire::nullableStr($wire['publishedAt'] ?? null),
            'items' => ConsentDocumentItem::listFromWire($wire),
        ];
    }
}
