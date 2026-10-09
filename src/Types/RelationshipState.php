<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * Where a customer relationship stands: `status` is a {@see RelationshipLifecycle} value
 * and `endedAt` the instant it ended, null while it runs. The absence of any lifecycle
 * record reads as "active".
 */
final class RelationshipState
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $endedAt,
    ) {
    }

    /** @param array<string,mixed>|null $wire */
    public static function fromWire(?array $wire): self
    {
        return new self(
            Wire::str(($wire ?? [])['status'] ?? null, RelationshipLifecycle::ACTIVE),
            Wire::nullableStr(($wire ?? [])['endedAt'] ?? null),
        );
    }

    public function isEnded(): bool
    {
        return $this->status === RelationshipLifecycle::ENDED;
    }
}
