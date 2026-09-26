<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One catalogue cell as a HOST SYSTEM reads it (GET /v1/catalog/cells, scope
 * 'retention'): which cell each of its holdings falls under, the purpose, the
 * declared ground, and which rule governs it.
 *
 * ARCHIVED CELLS ARE INCLUDED with their `status`, because a host may still hold
 * data collected under a cell the company has since retired, and that data still
 * has a rule to follow.
 *
 * 🔴 `retentionRuleKey` NULL IS THE REGISTER'S OWN GAP, SURFACED AND NEVER FILLED.
 * No rule means no decided duration, and the host must NOT invent one: it abstains,
 * and the gap is fixed in Agreely. Nothing here defaults a period.
 *
 * ⚠️ `legalBasis` is the RAW stored value, and its vocabulary DEPENDS ON THE REGIME
 * the response names ({@see CatalogCells::$regime}): a private P-39.1 tenant and a
 * public A-2.1 body use disjoint sets. It is the ground the company DECLARES for
 * using or communicating this cell, NOT the basis on which collection is lawful, and
 * a non-consent value removes none of the notice, access, rectification or retention
 * duties the tenant owes. Agreely records the declaration and certifies nothing.
 */
final class CatalogCell
{
    public function __construct(
        public readonly string $id,
        public readonly string $category,
        public readonly string $purpose,
        public readonly ?string $description,
        public readonly string $legalBasis,
        public readonly bool $sensitive,
        public readonly ?string $categoryEn,
        public readonly ?string $purposeEn,
        public readonly string $status,
        public readonly ?string $retentionRuleKey,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['id'] ?? null),
            Wire::str($wire['category'] ?? null),
            Wire::str($wire['purpose'] ?? null),
            Wire::nullableStr($wire['description'] ?? null),
            Wire::str($wire['legalBasis'] ?? null),
            Wire::bool($wire['sensitive'] ?? null),
            Wire::nullableStr($wire['categoryEn'] ?? null),
            Wire::nullableStr($wire['purposeEn'] ?? null),
            Wire::str($wire['status'] ?? null),
            Wire::nullableStr($wire['retentionRuleKey'] ?? null),
        );
    }

    /**
     * @param list<array<string,mixed>> $wire
     * @return list<self>
     */
    public static function listFromWire(array $wire): array
    {
        return array_map(static fn (array $w): self => self::fromWire($w), array_values($wire));
    }

    /**
     * Whether this cell has a DECIDED retention rule. False is the register's gap:
     * the host abstains from purging under the cell.
     */
    public function hasRule(): bool
    {
        return $this->retentionRuleKey !== null;
    }
}
