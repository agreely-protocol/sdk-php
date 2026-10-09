<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The answer to releasing a retention hold: the hold as released (every
 * {@see RetentionHold} member sits directly on it), the customer, and what the release
 * did to Agreely's own copy of the customer's identity ({@see AgreelyIdentityOutcome};
 * "erased" when this was the last hold on all the information and a destroyed or
 * anonymized declaration stands).
 */
final class ReleasedHold extends RetentionHold
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
        public readonly ?string $agreelyIdentity,
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
            agreelyIdentity: Wire::nullableStr($wire['agreelyIdentity'] ?? null),
        );
    }
}
