<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The information an organisation PUBLISHED with a consent document version (the
 * art. 8 / A-2.1 art. 65 information), in both languages and verbatim, so a host renders
 * what it collects against instead of paraphrasing it.
 */
final class ConsentDocumentDisclosure
{
    public function __construct(
        public readonly BilingualText $purpose,
        public readonly BilingualText $means,
        public readonly BilingualText $categories,
        public readonly BilingualText $retention,
        public readonly BilingualText $withdrawal,
        public readonly BilingualText $recipients,
        public readonly BilingualText $crossBorder,
        public readonly BilingualText $rights,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            BilingualText::fromWire($wire['purpose'] ?? null),
            BilingualText::fromWire($wire['means'] ?? null),
            BilingualText::fromWire($wire['categories'] ?? null),
            BilingualText::fromWire($wire['retention'] ?? null),
            BilingualText::fromWire($wire['withdrawal'] ?? null),
            BilingualText::fromWire($wire['recipients'] ?? null),
            BilingualText::fromWire($wire['crossBorder'] ?? null),
            BilingualText::fromWire($wire['rights'] ?? null),
        );
    }
}
