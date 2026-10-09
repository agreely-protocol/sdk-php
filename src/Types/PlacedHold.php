<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The answer to placing a retention hold: the hold as recorded (every
 * {@see RetentionHold} member sits directly on it), the customer, and `replayed`, true
 * when the same Idempotency-Key with the same body replayed the first answer (200)
 * instead of placing a second hold (201).
 */
final class PlacedHold extends RetentionHold
{
    public function __construct(
        string $id,
        string $status,
        HoldScope $scope,
        string $placedBy,
        string $startedOn,
        string $placedAt,
        ?string $releasedAt,
        ?string $ground,
        ?bool $hasProvision,
        ?string $reviewOn,
        ?bool $hasReleaseReason,
        public readonly string $customerRef,
        public readonly bool $recorded,
        public readonly bool $replayed,
    ) {
        parent::__construct(
            $id,
            $status,
            $scope,
            $placedBy,
            $startedOn,
            $placedAt,
            $releasedAt,
            $ground,
            $hasProvision,
            $reviewOn,
            $hasReleaseReason,
        );
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            ...parent::wireArgs($wire),
            customerRef: Wire::str($wire['customerRef'] ?? null),
            recorded: Wire::bool($wire['recorded'] ?? false),
            replayed: Wire::bool($wire['replayed'] ?? false),
        );
    }
}
