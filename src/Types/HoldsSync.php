<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One COMPLETE sync of the retention-hold feed (retention()->syncHolds()).
 *
 *   mode    MODE_SNAPSHOT (no changedSince): `holds` REPLACES your whole active set.
 *           MODE_DELTA (changedSince): every hold placed or released since, each with
 *           its status; upsert by id.
 *   cursor  persist it, and pass it as changedSince on the next sync.
 */
final class HoldsSync
{
    public const MODE_SNAPSHOT = 'snapshot';
    public const MODE_DELTA    = 'delta';

    /** @param list<RetentionHoldFeedItem> $holds */
    public function __construct(
        public readonly string $mode,
        public readonly array $holds,
        public readonly string $cursor,
    ) {
    }

    /**
     * The holds still in force in this sync, for a purge job to skip.
     *
     * @return list<RetentionHoldFeedItem>
     */
    public function active(): array
    {
        return array_values(array_filter($this->holds, static fn (RetentionHoldFeedItem $h): bool => $h->isActive()));
    }
}
