<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The printable signature sheet of a {@see ConsentSheet}: one Oui/Non pair per consent
 * ask, the information purposes listed without a box, the signature line and the
 * printed reference. It never names the person and never carries the claim link.
 *
 * `pdf` is the base64 the wire carries; {@see SignatureSheet::bytes()} decodes it.
 *
 * ⚠️ This is the BLANK sheet. Never send its hash as `evidence.pdfSha256`: the evidence
 * of a paper consent is the SIGNED sheet, hashed once it comes back.
 */
final class SignatureSheet
{
    public function __construct(
        public readonly string $documentVersionId,
        public readonly string $locale,
        public readonly string $contentType,
        public readonly string $filename,
        public readonly string $pdf,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['documentVersionId'] ?? null),
            Wire::str($wire['locale'] ?? null),
            Wire::str($wire['contentType'] ?? null, 'application/pdf'),
            Wire::str($wire['filename'] ?? null),
            Wire::str($wire['pdf'] ?? null),
        );
    }

    /** The PDF bytes to print (the base64 `pdf`, decoded). An undecodable body yields ''. */
    public function bytes(): string
    {
        $bytes = base64_decode($this->pdf, true);
        return $bytes === false ? '' : $bytes;
    }
}
