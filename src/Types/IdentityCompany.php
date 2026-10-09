<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The minimum GET /v1/whoami says about the key's organisation, so a host DISCOVERS
 * its tenant instead of hard-coding it. Deliberately short, because an API key is a
 * bearer credential and everything here leaks with it: no company id, no DID, no key
 * metadata, nothing about size, plan, usage or billing.
 *
 *   name            the organisation's name
 *   sector          "private" or "public" ({@see Regime::SECTOR_PRIVATE})
 *   statute         "P-39.1" or "A-2.1", the act that governs it
 *   publicPolicyUrl the ABSOLUTE url of its public privacy page, or null when that page
 *                   would not resolve. Link to it as given; never build it from a slug.
 */
final class IdentityCompany
{
    public function __construct(
        public readonly string $name,
        public readonly string $sector,
        public readonly string $statute,
        public readonly ?string $publicPolicyUrl,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['name'] ?? null),
            Wire::str($wire['sector'] ?? null),
            Wire::str($wire['statute'] ?? null),
            Wire::nullableStr($wire['publicPolicyUrl'] ?? null),
        );
    }

    /** The governing act as a {@see Regime}, the shape the catalog responses name it in. */
    public function regime(): Regime
    {
        return new Regime($this->sector, $this->statute);
    }
}
