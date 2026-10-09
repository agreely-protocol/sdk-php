<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One page of GET /v1/retention/holds (at most 500 rows). Every page but the last
 * carries `nextPageToken` (pass it back as pageToken, unchanged) and no `cursor`; the
 * LAST page carries `cursor`, the next sync's changedSince, and no token.
 */
final class HoldFeedPage
{
    /** @param list<HoldFeedEntry> $holds */
    public function __construct(
        public readonly array $holds,
        public readonly ?string $nextPageToken,
        public readonly ?string $cursor,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            array_map(HoldFeedEntry::fromWire(...), Wire::objects($wire, 'holds')),
            Wire::nullableStr($wire['nextPageToken'] ?? null),
            Wire::nullableStr($wire['cursor'] ?? null),
        );
    }

    public function isLast(): bool
    {
        return $this->nextPageToken === null || $this->nextPageToken === '';
    }
}
