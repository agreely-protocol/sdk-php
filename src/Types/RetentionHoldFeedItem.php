<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One row of the retention-hold FEED (scope 'holds'): a reference and what it covers,
 * and NOTHING that says why. No ground, no origin, no provision and no date beyond
 * `changedAt`: a list of references beside « rights_request » would be a list of people
 * who made a request. A purge job honours a hold from its status and scope alone.
 *
 * Delivery is AT LEAST ONCE: upsert by `id`. In a delta sync a row may be "released".
 */
final class RetentionHoldFeedItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $customerRef,
        public readonly string $status,
        public readonly HoldScope $scope,
        public readonly string $changedAt,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['id'] ?? null),
            Wire::str($wire['customerRef'] ?? null),
            Wire::str($wire['status'] ?? null),
            HoldScope::fromWire($wire['scope'] ?? null),
            Wire::str($wire['changedAt'] ?? null),
        );
    }

    /**
     * FAIL CLOSED: a row counts as a hold in place unless it says "released". A status
     * this client does not know, or a missing one, keeps the information rather than let
     * a purge destroy it.
     */
    public function isActive(): bool
    {
        return $this->status !== RetentionHold::STATUS_RELEASED;
    }
}
