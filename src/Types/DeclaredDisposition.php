<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The answer to declaring a disposition: the ledger row read back (every
 * {@see RetentionDispositionRecord} member sits directly on it), `recorded` true and
 * `status` "declared", and NOTHING STRONGER. Agreely recorded a declaration; it did not
 * observe, confirm or verify a destruction.
 *
 * `appended` is false when an identical declaration was already STANDING and was
 * replayed unchanged (200) instead of written twice. `warnings` flags what was recorded
 * as received (a hold in place).
 */
final class DeclaredDisposition extends RetentionDispositionRecord
{
    /** @param list<DispositionWarning> $warnings */
    public function __construct(
        string $id,
        string $disposition,
        ?string $retentionUntil,
        bool $hasReason,
        ?string $endedAtSnapshot,
        ?string $scheduleRef,
        ?string $basis,
        bool $hasBasisNote,
        string $declaredBy,
        string $declaredAt,
        ?string $agreelyIdentity,
        public readonly string $customerRef,
        public readonly bool $recorded,
        public readonly string $status,
        public readonly bool $appended,
        public readonly array $warnings,
    ) {
        parent::__construct(
            $id,
            $disposition,
            $retentionUntil,
            $hasReason,
            $endedAtSnapshot,
            $scheduleRef,
            $basis,
            $hasBasisNote,
            $declaredBy,
            $declaredAt,
            $agreelyIdentity,
        );
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            ...parent::wireArgs($wire),
            customerRef: Wire::str($wire['customerRef'] ?? null),
            recorded: Wire::bool($wire['recorded'] ?? false),
            status: Wire::str($wire['status'] ?? null),
            appended: Wire::bool($wire['appended'] ?? false),
            warnings: array_map(DispositionWarning::fromWire(...), Wire::objects($wire, 'warnings')),
        );
    }
}
