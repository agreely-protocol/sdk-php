<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One retention rule the organisation DECIDED (openapi.yaml RetentionRule). The
 * host reads it and executes under it; it never invents a duration.
 *
 * ARCHIVED RULES ARE INCLUDED in a listing, with their `status`, because a host may
 * still hold data a retired rule governs and that data still has a rule to follow.
 *
 * `label` is the organisation's own wording. `labelEn` is null when no English twin
 * was authored, and it is never a machine translation: show `label` in that case.
 *
 * ⚠️ `action` is the rule's INFINITIVE ({@see RetentionAction}). A declaration of
 * what a host did carries a {@see PurgeMethod} instead; use
 * {@see PurgeMethod::forRule()} to go from one to the other.
 */
final class RetentionRule
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $labelEn,
        public readonly RetentionDuration $duration,
        public readonly string $trigger,
        public readonly string $action,
        public readonly ?string $anonymizationProcessKey,
        public readonly string $status,
        public readonly ?string $archivedAt,
        public readonly string $updatedAt,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $duration = RetentionDuration::fromWireMember($wire, 'duration');
        return new self(
            Wire::str($wire['key'] ?? null),
            Wire::str($wire['label'] ?? null),
            Wire::nullableStr($wire['labelEn'] ?? null),
            $duration ?? new RetentionDuration(0, 'months'),
            Wire::str($wire['trigger'] ?? null),
            Wire::str($wire['action'] ?? null),
            Wire::nullableStr($wire['anonymizationProcessKey'] ?? null),
            Wire::str($wire['status'] ?? null),
            Wire::nullableStr($wire['archivedAt'] ?? null),
            Wire::str($wire['updatedAt'] ?? null),
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

    /** Whether the rule is still active (an archived one still governs data a host holds). */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * The rule, as the plain array a {@see RetentionRuleSnapshot} persists between
     * runs. Round-trips through {@see RetentionRule::fromWire()}.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'labelEn' => $this->labelEn,
            'duration' => $this->duration->toArray(),
            'trigger' => $this->trigger,
            'action' => $this->action,
            'anonymizationProcessKey' => $this->anonymizationProcessKey,
            'status' => $this->status,
            'archivedAt' => $this->archivedAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
