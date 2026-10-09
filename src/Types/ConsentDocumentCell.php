<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One catalog cell a published document version groups. `sensitive` is the PER-VERSION
 * snapshot frozen when the cell was grouped: what THIS version declared, which a later
 * catalog edit cannot change.
 */
final class ConsentDocumentCell
{
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly ?string $categoryEn,
        public readonly string $purpose,
        public readonly ?string $purposeEn,
        public readonly bool $sensitive,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['id'] ?? null),
            Wire::str($wire['category'] ?? null),
            Wire::nullableStr($wire['categoryEn'] ?? null),
            Wire::str($wire['purpose'] ?? null),
            Wire::nullableStr($wire['purposeEn'] ?? null),
            Wire::bool($wire['sensitive'] ?? false),
        );
    }

    /**
     * @param array<string,mixed> $wire
     * @return list<self>
     */
    public static function listFromWire(array $wire): array
    {
        return array_map(self::fromWire(...), Wire::objects($wire, 'items'));
    }
}
