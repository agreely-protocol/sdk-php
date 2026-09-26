<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One FROZEN statement resolved by the key a host stamped on a record at collection
 * (GET /v1/inventory/statements/{statementKey}).
 *
 * It resolves for as long as the record set exists, whatever became of the rule, the
 * cell or the set's later statements: that is the point of freezing it.
 *
 * ⚠️ `current` is FALSE once a later statement superseded it, and the terms are still
 * the ones frozen. That is not staleness to correct: the record was collected under
 * these terms, so these are the terms to show the person.
 */
final class ResolvedRetentionStatement
{
    public function __construct(
        public readonly RetentionStatement $statement,
        public readonly string $categoryKey,
        public readonly string $hostSystem,
        public readonly bool $current,
        public readonly ?string $supersededAt,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            RetentionStatement::fromWire($wire),
            Wire::str($wire['categoryKey'] ?? null),
            Wire::str($wire['hostSystem'] ?? null),
            Wire::bool($wire['current'] ?? null),
            Wire::nullableStr($wire['supersededAt'] ?? null),
        );
    }
}
