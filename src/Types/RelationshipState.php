<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * Where a customer relationship stands (art. 23 / A-2.1 art. 73): `status` is "active",
 * "ending" or "ended", and `endedAt` is the instant it ended, null while it runs. The
 * absence of any lifecycle record reads as "active".
 */
final class RelationshipState
{
    public const ACTIVE = 'active';
    public const ENDING = 'ending';
    public const ENDED  = 'ended';

    public function __construct(
        public readonly string $status,
        public readonly ?string $endedAt,
    ) {
    }

    /** @param array<string,mixed>|null $wire */
    public static function fromWire(?array $wire): self
    {
        return new self(
            Wire::str(($wire ?? [])['status'] ?? null, self::ACTIVE),
            Wire::nullableStr(($wire ?? [])['endedAt'] ?? null),
        );
    }

    public function isEnded(): bool
    {
        return $this->status === self::ENDED;
    }
}
