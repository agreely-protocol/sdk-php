<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * ONE published consent document with its full published information
 * (GET /v1/consent-documents/{code}): the {@see ConsentDocument} summary fields plus the
 * disclosure, the responsable, the declared automated-decision and profiling flags, and
 * the public integrity copy of the version.
 *
 * `integrity` DOCUMENTS the version (its IPFS copy and anchor status); it does not
 * certify its content.
 */
final class ConsentDocumentDetail
{
    /**
     * @param list<ConsentDocumentCell> $items
     * @param array{name:string,contact:string} $responsable
     * @param array{ipfsCid:?string,anchorStatus:string} $integrity
     */
    public function __construct(
        public readonly string $code,
        public readonly string $documentVersionId,
        public readonly string $version,
        public readonly string $name,
        public readonly ?string $nameEn,
        public readonly string $effectiveDate,
        public readonly ?string $publishedAt,
        public readonly array $items,
        public readonly ConsentDocumentDisclosure $disclosure,
        public readonly array $responsable,
        public readonly DeclaredFlag $automatedDecision,
        public readonly DeclaredFlag $profiling,
        public readonly array $integrity,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $summary = ConsentDocument::fromWire($wire);
        $responsable = Wire::object($wire, 'responsable') ?? [];
        $integrity = Wire::object($wire, 'integrity') ?? [];
        return new self(
            $summary->code,
            $summary->documentVersionId,
            $summary->version,
            $summary->name,
            $summary->nameEn,
            $summary->effectiveDate,
            $summary->publishedAt,
            $summary->items,
            ConsentDocumentDisclosure::fromWire(Wire::object($wire, 'disclosure') ?? []),
            [
                'name' => Wire::str($responsable['name'] ?? null),
                'contact' => Wire::str($responsable['contact'] ?? null),
            ],
            DeclaredFlag::fromWire($wire['automatedDecision'] ?? null),
            DeclaredFlag::fromWire($wire['profiling'] ?? null),
            [
                'ipfsCid' => Wire::nullableStr($integrity['ipfsCid'] ?? null),
                'anchorStatus' => Wire::str($integrity['anchorStatus'] ?? null, 'none'),
            ],
        );
    }
}
