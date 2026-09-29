<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The 201 body from POST /v1/verbal-consents: a consent given BY TELEPHONE and
 * documented by the organisation. Always tier "verbal", assurance
 * "company_documented": there is no document at all, so the proof is the
 * organisation's word, never "signed", "validated" or "certified".
 *
 * `consentRefs` holds the handles of the "yes" purposes and of the acknowledged
 * lines. `acknowledged` lists the lines given for information, recorded as
 * acknowledged from the script (never a consent). `asksDeclined` is true when every
 * ask was answered "no". `anchored` is false at record time.
 */
final class VerbalConsentResult
{
    /**
     * @param list<string> $consentRefs
     * @param list<AcknowledgedLine> $acknowledged
     */
    public function __construct(
        public readonly string $consentId,
        public readonly string $merkleRoot,
        public readonly array $consentRefs,
        public readonly string $tier,
        public readonly string $assurance,
        public readonly bool $anchored,
        public readonly bool $paperExpected,
        public readonly array $acknowledged,
        public readonly bool $asksDeclined,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['consentId'] ?? null),
            Wire::str($wire['merkleRoot'] ?? null),
            Wire::strings($wire, 'consentRefs'),
            Wire::str($wire['tier'] ?? null),
            Wire::str($wire['assurance'] ?? null),
            Wire::bool($wire['anchored'] ?? false),
            Wire::bool($wire['paperExpected'] ?? false),
            AcknowledgedLine::listFromWire($wire),
            Wire::bool($wire['asksDeclined'] ?? false),
        );
    }
}
