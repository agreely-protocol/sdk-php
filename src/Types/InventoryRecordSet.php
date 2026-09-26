<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One record set a host system DECLARED it holds, with what Agreely decided for it.
 *
 * ⚠️ `declared`, never `verified`: Agreely observes nothing in the host's systems.
 * What a set says it holds is what the host declared.
 *
 * A WITHDRAWN set stays listed, marked withdrawn, with the instant it was withdrawn:
 * a set that stops being declared is not erased from the register.
 *
 * 🔴 A set whose {@see InventoryDecision::isDecided()} is false has NO duration and
 * NO statement. The host abstains; it never picks a number of its own.
 */
final class InventoryRecordSet
{
    public const STATUS_DECLARED  = 'declared';
    public const STATUS_WITHDRAWN = 'withdrawn';

    /** @param list<InventoryField> $fields */
    public function __construct(
        public readonly string $key,
        public readonly string $hostSystem,
        public readonly string $label,
        public readonly ?string $labelEn,
        public readonly array $fields,
        public readonly string $status,
        public readonly ?string $firstDeclaredAt,
        public readonly ?string $lastDeclaredAt,
        public readonly ?string $withdrawnAt,
        public readonly InventoryDecision $decision,
        public readonly ?RetentionStatement $retentionStatement,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $decision = $wire['decision'] ?? null;
        /** @var array<string,mixed> $decision */
        $decision = is_array($decision) ? $decision : [];
        return new self(
            Wire::str($wire['key'] ?? null),
            Wire::str($wire['hostSystem'] ?? null),
            Wire::str($wire['label'] ?? null),
            Wire::nullableStr($wire['labelEn'] ?? null),
            InventoryField::listFromWire(Wire::objects($wire, 'fields')),
            Wire::str($wire['status'] ?? null),
            Wire::nullableStr($wire['firstDeclaredAt'] ?? null),
            Wire::nullableStr($wire['lastDeclaredAt'] ?? null),
            Wire::nullableStr($wire['withdrawnAt'] ?? null),
            InventoryDecision::fromWire($decision),
            RetentionStatement::fromWireMember($wire, 'retentionStatement'),
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

    public function isWithdrawn(): bool
    {
        return $this->status === self::STATUS_WITHDRAWN;
    }
}
