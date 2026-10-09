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
 *
 * Not final: {@see PlacedHold} and {@see ReleasedHold} extend it with the answer's own
 * fields, as the TypeScript twin's interfaces do.
 */
class RetentionHold
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_RELEASED = 'released';

    public const PLACED_BY_API          = 'api';
    public const PLACED_BY_ORGANIZATION = 'organization';

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly HoldScope $scope,
        public readonly string $placedBy,
        public readonly string $startedOn,
        public readonly string $placedAt,
        public readonly ?string $releasedAt,
        public readonly ?string $ground,
        public readonly ?bool $hasProvision,
        public readonly ?string $reviewOn,
        public readonly ?bool $hasReleaseReason,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(...self::wireArgs($wire));
    }

    /**
     * @param list<array<string,mixed>> $wire
     * @return list<self>
     */
    public static function listFromWire(array $wire): array
    {
        return array_map(self::fromWire(...), array_values($wire));
    }

    /**
     * FAIL CLOSED: a hold counts as in place unless it says "released". A status this
     * client does not know, or a missing one, keeps the information rather than let a
     * purge destroy it.
     */
    public function isActive(): bool
    {
        return $this->status !== self::STATUS_RELEASED;
    }

    /**
     * The hold's own members, read off the wire, as the named arguments of the
     * constructor, so a subclass reads them the same way.
     *
     * @param array<string,mixed> $wire
     * @return array{id:string,status:string,scope:HoldScope,placedBy:string,startedOn:string,placedAt:string,releasedAt:?string,ground:?string,hasProvision:?bool,reviewOn:?string,hasReleaseReason:?bool}
     */
    protected static function wireArgs(array $wire): array
    {
        return [
            'id' => Wire::str($wire['id'] ?? null),
            'status' => Wire::str($wire['status'] ?? null),
            'scope' => HoldScope::fromWire($wire['scope'] ?? null),
            'placedBy' => Wire::str($wire['placedBy'] ?? null),
            'startedOn' => Wire::str($wire['startedOn'] ?? null),
            'placedAt' => Wire::str($wire['placedAt'] ?? null),
            'releasedAt' => Wire::nullableStr($wire['releasedAt'] ?? null),
            'ground' => Wire::nullableStr($wire['ground'] ?? null),
            'hasProvision' => array_key_exists('hasProvision', $wire) ? Wire::bool($wire['hasProvision']) : null,
            'reviewOn' => Wire::nullableStr($wire['reviewOn'] ?? null),
            'hasReleaseReason' => array_key_exists('hasReleaseReason', $wire) ? Wire::bool($wire['hasReleaseReason']) : null,
        ];
    }
}
