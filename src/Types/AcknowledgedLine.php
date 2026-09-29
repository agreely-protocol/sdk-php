<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A line the consent document gives FOR INFORMATION, recorded as the person's
 * acknowledgement and NEVER as a consent. /v1/check answers it by its declared basis,
 * exactly as if there were no record, and with no assurance or tier.
 */
final class AcknowledgedLine
{
    public function __construct(
        public readonly string $category,
        public readonly string $purpose,
        public readonly string $consentRef,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['category'] ?? null),
            Wire::str($wire['purpose'] ?? null),
            Wire::str($wire['consentRef'] ?? null),
        );
    }

    /**
     * @param array<string,mixed> $wire
     * @return list<self>
     */
    public static function listFromWire(array $wire, string $key = 'acknowledged'): array
    {
        return array_map(self::fromWire(...), Wire::objects($wire, $key));
    }
}
