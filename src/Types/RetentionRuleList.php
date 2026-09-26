<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The body of GET /v1/retention/rules.
 *
 * `cursor` is the next `changedSince`. It deliberately TRAILS the read by a few
 * minutes, so a rule may be sent twice; it is never missed.
 *
 * ⚠️ `ruleKeys` is the COMPLETE current key set, even on an incremental read. That
 * is how a rule DELETED in Agreely becomes visible: as a key that disappeared from
 * here. `rules` on an incremental read carries only what changed, so never treat it
 * as the whole set.
 */
final class RetentionRuleList
{
    /**
     * @param list<RetentionRule> $rules
     * @param list<string> $ruleKeys
     */
    public function __construct(
        public readonly string $cursor,
        public readonly array $rules,
        public readonly array $ruleKeys,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['cursor'] ?? null),
            RetentionRule::listFromWire(Wire::objects($wire, 'rules')),
            Wire::strings($wire, 'ruleKeys'),
        );
    }
}
