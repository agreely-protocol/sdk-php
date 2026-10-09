<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The answer to releasing a retention hold: the hold as released, and what the release
 * did to Agreely's own copy of the customer's identity ({@see AgreelyIdentity}; "erased"
 * when this was the last hold on all the information and a destroyed or anonymized
 * declaration stands).
 */
final class ReleasedHold
{
    public function __construct(
        public readonly string $customerRef,
        public readonly bool $recorded,
        public readonly RetentionHold $hold,
        public readonly ?string $agreelyIdentity,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['customerRef'] ?? null),
            Wire::bool($wire['recorded'] ?? false),
            RetentionHold::fromWire($wire),
            Wire::nullableStr($wire['agreelyIdentity'] ?? null),
        );
    }
}
