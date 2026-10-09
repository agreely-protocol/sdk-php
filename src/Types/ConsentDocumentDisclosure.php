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
        public readonly LocalizedText $purpose,
        public readonly LocalizedText $means,
        public readonly LocalizedText $categories,
        public readonly LocalizedText $retention,
        public readonly LocalizedText $withdrawal,
        public readonly LocalizedText $recipients,
        public readonly LocalizedText $crossBorder,
        public readonly LocalizedText $rights,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            LocalizedText::fromWire($wire['purpose'] ?? null),
            LocalizedText::fromWire($wire['means'] ?? null),
            LocalizedText::fromWire($wire['categories'] ?? null),
            LocalizedText::fromWire($wire['retention'] ?? null),
            LocalizedText::fromWire($wire['withdrawal'] ?? null),
            LocalizedText::fromWire($wire['recipients'] ?? null),
            LocalizedText::fromWire($wire['crossBorder'] ?? null),
            LocalizedText::fromWire($wire['rights'] ?? null),
        );
    }
}
