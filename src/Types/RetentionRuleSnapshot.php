<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The rules a host PERSISTS BETWEEN RUNS, so a purge job can reason about an
 * outage and so the next run reads only what changed.
 *
 * Opaque in intent: store it whole and hand it back. In PHP that means
 * {@see RetentionRuleSnapshot::toArray()} into your own store (a JSON column, a
 * file, a cache entry) and {@see RetentionRuleSnapshot::fromArray()} on the way
 * back. Do not rebuild it field by field.
 *
 *   cursor    the server cursor, the next `changedSince`
 *   fetchedAt when THIS process last read the rules from Agreely (RFC 3339 UTC,
 *             the local clock), which is what bounds a degraded run
 *   rules     the complete current rule set
 *
 * ⚠️ `fetchedAt` IS THE LOCAL CLOCK, not a server instant, and it is deliberate: it
 * answers "how long since I last heard from Agreely", which is the only question a
 * bounded degraded purge may ask. A skewed clock therefore widens or narrows that
 * bound, so keep the host's clock honest.
 */
final class RetentionRuleSnapshot
{
    /** @param list<RetentionRule> $rules */
    public function __construct(
        public readonly string $cursor,
        public readonly string $fetchedAt,
        public readonly array $rules,
    ) {
    }

    /**
     * Rebuild a snapshot a previous run persisted. Returns null for anything that
     * is not a usable snapshot (an absent store, a truncated row, a shape from an
     * older version), because a purge job must then read live or abstain rather
     * than act on half a snapshot.
     *
     * @param array<array-key,mixed>|null $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null) {
            return null;
        }
        $cursor = $stored['cursor'] ?? null;
        $fetchedAt = $stored['fetchedAt'] ?? null;
        $rules = $stored['rules'] ?? null;
        if (!is_string($cursor) || $cursor === '' || !is_string($fetchedAt) || !is_array($rules)) {
            return null;
        }
        $parsed = [];
        foreach (array_values($rules) as $rule) {
            if (!is_array($rule)) {
                return null;
            }
            /** @var array<string,mixed> $rule */
            $parsed[] = RetentionRule::fromWire($rule);
        }
        return new self($cursor, $fetchedAt, $parsed);
    }

    /**
     * The snapshot as a plain, JSON-serialisable array. Round-trips through
     * {@see RetentionRuleSnapshot::fromArray()}.
     *
     * @return array{cursor:string,fetchedAt:string,rules:list<array<string,mixed>>}
     */
    public function toArray(): array
    {
        return [
            'cursor' => $this->cursor,
            'fetchedAt' => $this->fetchedAt,
            'rules' => array_map(static fn (RetentionRule $r): array => $r->toArray(), $this->rules),
        ];
    }

    /** Epoch milliseconds of `fetchedAt`, or null when it cannot be read. */
    public function fetchedAtMs(): ?float
    {
        try {
            $at = new \DateTimeImmutable($this->fetchedAt);
        } catch (\Exception) {
            return null;
        }
        return (float) $at->format('U') * 1000.0 + (float) $at->format('v');
    }
}
