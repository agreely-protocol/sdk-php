<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One retention hold on one customer, as the host may see it: METADATA, never the
 * provision text or the release reason.
 *
 *   placedBy  "api" when your systems placed it through /v1, "organization" for anyone
 *             in the organisation's Agreely account (the rights register included)
 *   ground, hasProvision, reviewOn, hasReleaseReason
 *             read back ONLY on a hold placed by "api" (what your system itself wrote),
 *             null otherwise. A hold the organisation placed says only that it holds,
 *             what it covers and since when.
 *
 * NOTHING RELEASES A HOLD BY ITSELF: `reviewOn` is a reminder, never an end.
 */
final class RetentionHold
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_RELEASED = 'released';

    public const PLACED_BY_API          = 'api';
    public const PLACED_BY_ORGANIZATION = 'organization';

    public const GROUND_RIGHTS_REQUEST = 'rights_request';
    public const GROUND_OTHER_LAW      = 'other_law';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly HoldScope $scope,
        public readonly string $placedBy,
        public readonly string $startedOn,
        public readonly string $placedAt,
        public readonly ?string $releasedAt,
        public readonly ?string $ground = null,
        public readonly ?bool $hasProvision = null,
        public readonly ?string $reviewOn = null,
        public readonly ?bool $hasReleaseReason = null,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['id'] ?? null),
            Wire::str($wire['status'] ?? null),
            HoldScope::fromWire($wire['scope'] ?? null),
            Wire::str($wire['placedBy'] ?? null),
            Wire::str($wire['startedOn'] ?? null),
            Wire::str($wire['placedAt'] ?? null),
            Wire::nullableStr($wire['releasedAt'] ?? null),
            Wire::nullableStr($wire['ground'] ?? null),
            array_key_exists('hasProvision', $wire) ? Wire::bool($wire['hasProvision']) : null,
            Wire::nullableStr($wire['reviewOn'] ?? null),
            array_key_exists('hasReleaseReason', $wire) ? Wire::bool($wire['hasReleaseReason']) : null,
        );
    }

    /**
     * @param list<array<string,mixed>> $wire
     * @return list<self>
     */
    public static function listFromWire(array $wire): array
    {
        return array_map(self::fromWire(...), array_values($wire));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
