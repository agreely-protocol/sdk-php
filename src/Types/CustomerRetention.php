<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One customer's retention posture (GET /v1/customers/{customerRef}/retention, scope
 * 'registry'): the relationship, the derived clock ({@see RetentionClock}), the STANDING
 * declared disposition (null when none was declared), and the retention holds (every
 * active one and the 20 most recently released; `releasedTruncated` when more exist).
 */
final class CustomerRetention
{
    /** @param list<RetentionHold> $holds */
    public function __construct(
        public readonly string $customerRef,
        public readonly RelationshipState $relationship,
        public readonly RetentionClock $clock,
        public readonly ?Disposition $disposition,
        public readonly array $holds,
        public readonly bool $releasedTruncated,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $disposition = Wire::object($wire, 'disposition');
        return new self(
            Wire::str($wire['customerRef'] ?? null),
            RelationshipState::fromWire(Wire::object($wire, 'relationship')),
            RetentionClock::fromWire(Wire::object($wire, 'clock') ?? []),
            $disposition === null ? null : Disposition::fromWire($disposition),
            RetentionHold::listFromWire(Wire::objects($wire, 'holds')),
            Wire::bool($wire['releasedTruncated'] ?? false),
        );
    }

    /**
     * The holds still in place.
     *
     * @return list<RetentionHold>
     */
    public function activeHolds(): array
    {
        return array_values(array_filter($this->holds, static fn (RetentionHold $h): bool => $h->isActive()));
    }
}
