<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

use Agreely\Sdk\HostInput;

/**
 * A published text in both languages, read back VERBATIM. Neither language falls back
 * to the other: serving the French statutory text to an English reader would misreport
 * what the organisation published. A null side was not written.
 */
final class LocalizedText
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

    /**
     * The text in $locale, or null when that side was not written. Never the other side.
     * A locale other than "fr" or "en" is refused (AgreelyConfigError), never read as French.
     */
    public function text(string $locale): ?string
    {
        return HostInput::locale($locale, 'LocalizedText::text: locale') === DocumentLocale::EN ? $this->en : $this->fr;
    }
}
