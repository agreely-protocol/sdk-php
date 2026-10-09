<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A published disclosure text in both languages, read back VERBATIM. Neither language
 * falls back to the other: serving the French statutory text to an English reader would
 * misreport what the organisation published. A null side was not written.
 */
final class BilingualText
{
    public function __construct(
        public readonly ?string $fr,
        public readonly ?string $en,
    ) {
    }

    public static function fromWire(mixed $wire): self
    {
        if (!is_array($wire)) {
            return new self(null, null);
        }
        /** @var array<string,mixed> $wire */
        return new self(Wire::nullableStr($wire['fr'] ?? null), Wire::nullableStr($wire['en'] ?? null));
    }

    /** The text in $locale ("fr" or "en"), or null when that side was not written. Never the other side. */
    public function text(string $locale): ?string
    {
        return $locale === 'en' ? $this->en : $this->fr;
    }
}
