<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * What a retention hold covers: ALL the information about the person (`all` true), or
 * the listed retention rules (keys of GET /v1/retention/rules) and catalogue cells (keys
 * of GET /v1/catalog/cells). On the wire it is the string "all" or {rules, cells}.
 *
 * A purge job skips a record when its rule or its cell is listed, or when `all` is true.
 * Only a hold on all the information also keeps Agreely's registry identity after a
 * declared destruction.
 */
final class HoldScope
{
    /**
     * @param list<string> $rules
     * @param list<string> $cells
     */
    public function __construct(
        public readonly bool $all,
        public readonly array $rules = [],
        public readonly array $cells = [],
    ) {
    }

    public static function fromWire(mixed $wire): self
    {
        if (!is_array($wire)) {
            // "all", and anything this client cannot read, covers everything: a hold read
            // wrongly must keep too much, never too little.
            return new self(true);
        }
        /** @var array<string,mixed> $wire */
        return new self(false, Wire::strings($wire, 'rules'), Wire::strings($wire, 'cells'));
    }

    /** Whether this hold suspends the retention rule $ruleKey, or the catalogue cell $cellKey. */
    public function covers(?string $ruleKey = null, ?string $cellKey = null): bool
    {
        return $this->all
            || ($ruleKey !== null && in_array($ruleKey, $this->rules, true))
            || ($cellKey !== null && in_array($cellKey, $this->cells, true));
    }
}
