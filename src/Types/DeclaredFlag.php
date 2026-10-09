<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * A flag the organisation DECLARED on a published document (an automated decision,
 * profiling), with its own note. Agreely records the declaration; it does not assess it.
 */
final class DeclaredFlag
{
    public function __construct(
        public readonly bool $declared,
        public readonly BilingualText $note,
    ) {
    }

    public static function fromWire(mixed $wire): self
    {
        if (!is_array($wire)) {
            return new self(false, new BilingualText(null, null));
        }
        /** @var array<string,mixed> $wire */
        return new self(Wire::bool($wire['declared'] ?? false), BilingualText::fromWire($wire['note'] ?? null));
    }
}
