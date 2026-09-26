<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The body of PUT /v1/inventory/categories: the host system that declared, how many
 * of its sets the declaration WITHDREW, and every set it now holds.
 *
 * 🔴 `withdrawn` IS THE NUMBER TO LOOK AT. The endpoint takes a COMPLETE LIST, so
 * every set this system had declared before and that was missing from the call is now
 * withdrawn. A non-zero count on a run that meant to change nothing is the sign that
 * the caller built a partial list, and that is worth failing a job over.
 */
final class InventoryDeclaration
{
    /** @param list<InventoryRecordSet> $categories */
    public function __construct(
        public readonly string $hostSystem,
        public readonly int $withdrawn,
        public readonly array $categories,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $withdrawn = $wire['withdrawn'] ?? null;
        return new self(
            Wire::str($wire['hostSystem'] ?? null),
            is_numeric($withdrawn) ? (int) $withdrawn : 0,
            InventoryRecordSet::listFromWire(Wire::objects($wire, 'categories')),
        );
    }
}
