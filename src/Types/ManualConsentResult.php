<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The 201 body from recording a manual / offline (company-attested) consent.
 * `assurance` is always "company_attested" for this path (vs the citizen-signed
 * live flow); `anchored` is false at record time.
 *
 * `consentRefs` holds one handle per recorded cell, the acknowledged lines included.
 * `acknowledged` lists the lines the document gives for information, which the
 * server added as an acknowledgement (never a consent). `asksDeclined` is true when
 * no consent ask is on the record (every ask answered "no"): only the
 * acknowledgement was recorded.
 */
final class ManualConsentResult
{
    /**
     * @param list<string> $consentRefs one 0x-hex enforcement handle per recorded cell
     * @param list<AcknowledgedLine> $acknowledged
     */
    public function __construct(
        public readonly string $consentId,
        public readonly string $merkleRoot,
        public readonly array $consentRefs,
        public readonly string $assurance,
        public readonly bool $anchored,
        public readonly array $acknowledged = [],
        public readonly bool $asksDeclined = false,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['consentId'] ?? null),
            Wire::str($wire['merkleRoot'] ?? null),
            Wire::strings($wire, 'consentRefs'),
            Wire::str($wire['assurance'] ?? null, 'company_attested'),
            Wire::bool($wire['anchored'] ?? false),
            AcknowledgedLine::listFromWire($wire),
            Wire::bool($wire['asksDeclined'] ?? false),
        );
    }
}
