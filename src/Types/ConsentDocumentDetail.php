<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * ONE published consent document with its full published information
 * (GET /v1/consent-documents/{code}): every {@see ConsentDocumentSummary} member, plus
 * the disclosure, the responsable, the declared automated-decision and profiling flags,
 * and the public integrity copy of the version.
 *
 * `integrity` DOCUMENTS the version (its IPFS copy and anchor status); it does not
 * certify its content.
 */
final class ConsentDocumentDetail extends ConsentDocumentSummary
{
    /**
     * @param list<ConsentDocumentItem> $items
     * @param array{name:string,contact:string} $responsable
     * @param array{ipfsCid:?string,anchorStatus:string} $integrity
     */
    public function __construct(
        string $code,
        string $documentVersionId,
        string $version,
        string $name,
        ?string $nameEn,
        string $effectiveDate,
        ?string $publishedAt,
        array $items,
        public readonly ConsentDocumentDisclosure $disclosure,
        public readonly array $responsable,
        public readonly DeclaredFlag $automatedDecision,
        public readonly DeclaredFlag $profiling,
        public readonly array $integrity,
    ) {
        parent::__construct($code, $documentVersionId, $version, $name, $nameEn, $effectiveDate, $publishedAt, $items);
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $responsable = Wire::object($wire, 'responsable') ?? [];
        $integrity = Wire::object($wire, 'integrity') ?? [];
        return new self(
            ...parent::wireArgs($wire),
            disclosure: ConsentDocumentDisclosure::fromWire(Wire::object($wire, 'disclosure') ?? []),
            responsable: [
                'name' => Wire::str($responsable['name'] ?? null),
                'contact' => Wire::str($responsable['contact'] ?? null),
            ],
            automatedDecision: DeclaredFlag::fromWire($wire['automatedDecision'] ?? null),
            profiling: DeclaredFlag::fromWire($wire['profiling'] ?? null),
            integrity: [
                'ipfsCid' => Wire::nullableStr($integrity['ipfsCid'] ?? null),
                'anchorStatus' => Wire::str($integrity['anchorStatus'] ?? null, 'none'),
            ],
        );
    }
}
