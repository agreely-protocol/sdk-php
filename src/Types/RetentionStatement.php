<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A FROZEN retention statement: the terms that were in force when it was frozen,
 * plus the plain-language sentence rendered from them in both locales.
 *
 * 🔴 FROZEN IS THE POINT. A record collected under « 3 mois » keeps a key that still
 * resolves to « 3 mois » after the rule becomes 2. The duration is decided and
 * changed in Agreely; the host reads it, stamps the key on the record, and never
 * recomputes the terms.
 *
 * `decided` false is a gap row: duration, trigger, action and text are all null and
 * the host ABSTAINS. The sentence is Agreely's frame around the frozen facts,
 * re-rendered at every read; the facts themselves never change.
 */
final class RetentionStatement
{
    public function __construct(
        public readonly string $key,
        public readonly int $revision,
        public readonly ?string $frozenAt,
        public readonly bool $decided,
        public readonly ?RetentionDuration $duration,
        public readonly ?string $trigger,
        public readonly ?string $action,
        public readonly ?string $textFr,
        public readonly ?string $textEn,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $revision = $wire['revision'] ?? null;
        $text = $wire['text'] ?? null;
        /** @var array<string,mixed> $text */
        $text = is_array($text) ? $text : [];
        return new self(
            Wire::str($wire['key'] ?? null),
            is_numeric($revision) ? (int) $revision : 0,
            Wire::nullableStr($wire['frozenAt'] ?? null),
            Wire::bool($wire['decided'] ?? null),
            RetentionDuration::fromWireMember($wire, 'duration'),
            Wire::nullableStr($wire['trigger'] ?? null),
            Wire::nullableStr($wire['action'] ?? null),
            Wire::nullableStr($text['fr'] ?? null),
            Wire::nullableStr($text['en'] ?? null),
        );
    }

    /**
     * The statement under a member of a larger response, or null when the set has
     * none (not linked, or its cell has no rule).
     *
     * @param array<string,mixed> $wire
     */
    public static function fromWireMember(array $wire, string $key): ?self
    {
        $member = $wire[$key] ?? null;
        if (!is_array($member)) {
            return null;
        }
        /** @var array<string,mixed> $member */
        return self::fromWire($member);
    }

    /**
     * The frozen sentence in a locale ("fr" or "en"), or null when the statement is
     * not decided. Never translate it yourself: it is rendered from the frozen terms.
     */
    public function text(string $locale): ?string
    {
        return $locale === 'en' ? $this->textEn : $this->textFr;
    }
}
