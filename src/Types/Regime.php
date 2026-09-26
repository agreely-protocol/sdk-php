<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The act governing a tenant. Named on a response because `legalBasis` carries two
 * DISJOINT value sets: a consumer receiving "attributions" with no context cannot
 * tell which statute it is reading, nor cite the right one.
 *
 *   sector  "private" (an enterprise) or "public" (a public body)
 *   statute "P-39.1" or "A-2.1"
 *
 * Read the regime BEFORE switching on a cell's `legalBasis`.
 */
final class Regime
{
    public const SECTOR_PRIVATE = 'private';
    public const SECTOR_PUBLIC  = 'public';

    public function __construct(
        public readonly string $sector,
        public readonly string $statute,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(Wire::str($wire['sector'] ?? null), Wire::str($wire['statute'] ?? null));
    }

    /**
     * The regime under a member of a larger response, or null when absent.
     *
     * @param array<string,mixed> $wire
     */
    public static function fromWireMember(array $wire, string $key): ?self
    {
        $member = $wire[$key] ?? null;
        if (!is_array($member)) {
            return null;
        }
        /** @var array<string,mixed> $member */
        return self::fromWire($member);
    }
}
