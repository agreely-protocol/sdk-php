<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The stored purge declaration (201 on a first send, 200 on an idempotent replay),
 * echoed to the DECLARING key only.
 *
 * ⚠️ `status` is always "declared", NEVER "verified". Agreely records that the host
 * said a purge ran; it observes nothing in the host's systems and does not verify
 * that the purge happened, was complete, or ran at the declared instant. An anchor
 * over it documents that Agreely recorded the declaration no later than its
 * anchoring date, and nothing more.
 *
 * `replayed` is true when this body came back from the store under the same
 * Idempotency-Key: the values are the STORED ones, so compare them if your caller
 * needs to know what the original declaration said.
 */
final class PurgeDeclaration
{
    /** @param list<PurgeWarning> $warnings */
    public function __construct(
        public readonly string $id,
        public readonly string $ruleKey,
        public readonly bool $recorded,
        public readonly string $status,
        public readonly bool $replayed,
        public readonly string $ranAt,
        public readonly int $recordsAffected,
        public readonly string $method,
        public readonly ?string $anonymizationProcessKey,
        public readonly string $coveredFrom,
        public readonly string $coveredUntil,
        public readonly string $hostSystem,
        public readonly string $hostCategory,
        public readonly string $declaredBy,
        public readonly string $declaredAt,
        public readonly int $referencesRecorded,
        public readonly array $warnings,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $records = $wire['recordsAffected'] ?? null;
        $references = $wire['referencesRecorded'] ?? null;
        return new self(
            Wire::str($wire['id'] ?? null),
            Wire::str($wire['ruleKey'] ?? null),
            Wire::bool($wire['recorded'] ?? null),
            Wire::str($wire['status'] ?? null),
            Wire::bool($wire['replayed'] ?? null),
            Wire::str($wire['ranAt'] ?? null),
            is_numeric($records) ? (int) $records : 0,
            Wire::str($wire['method'] ?? null),
            Wire::nullableStr($wire['anonymizationProcessKey'] ?? null),
            Wire::str($wire['coveredFrom'] ?? null),
            Wire::str($wire['coveredUntil'] ?? null),
            Wire::str($wire['hostSystem'] ?? null),
            Wire::str($wire['hostCategory'] ?? null),
            Wire::str($wire['declaredBy'] ?? null),
            Wire::str($wire['declaredAt'] ?? null),
            is_numeric($references) ? (int) $references : 0,
            PurgeWarning::listFromWire(Wire::objects($wire, 'warnings')),
        );
    }
}
