<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One host-DECLARED disposition of a customer's information, as METADATA: the outcome,
 * when, by which key, until when. The justification and the basis note are reported as
 * booleans (`hasReason`, `hasBasisNote`), never as text.
 *
 *   disposition      "destroyed" | "anonymized" | "legal_hold" (the class constants)
 *   retentionUntil   the declared end of a legal-hold conservation; null on a disposal
 *   endedAtSnapshot  the FROZEN end of the relationship the declaration was made against
 *   scheduleRef      the conservation rule you named, echoed verbatim and never verified
 *   basis            the frozen declared ground CODE the client was held on
 *   agreelyIdentity  what it did to Agreely's own copy of the identity ({@see AgreelyIdentity})
 *
 * ⚠️ DECLARED, NEVER VERIFIED. Agreely observed nothing: the information lives in your
 * systems, and it records who declared what and when.
 */
final class Disposition
{
    public const DESTROYED  = 'destroyed';
    public const ANONYMIZED = 'anonymized';
    public const LEGAL_HOLD = 'legal_hold';

    /** The closed outcome vocabulary. There is no "nothing applied": that is no entry. */
    public const ALL = [self::DESTROYED, self::ANONYMIZED, self::LEGAL_HOLD];

    public function __construct(
        public readonly string $id,
        public readonly string $disposition,
        public readonly ?string $retentionUntil,
        public readonly bool $hasReason,
        public readonly ?string $endedAtSnapshot,
        public readonly ?string $scheduleRef,
        public readonly ?string $basis,
        public readonly bool $hasBasisNote,
        public readonly string $declaredBy,
        public readonly string $declaredAt,
        public readonly ?string $agreelyIdentity,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(
            Wire::str($wire['id'] ?? null),
            Wire::str($wire['disposition'] ?? null),
            Wire::nullableStr($wire['retentionUntil'] ?? null),
            Wire::bool($wire['hasReason'] ?? false),
            Wire::nullableStr($wire['endedAtSnapshot'] ?? null),
            Wire::nullableStr($wire['scheduleRef'] ?? null),
            Wire::nullableStr($wire['basis'] ?? null),
            Wire::bool($wire['hasBasisNote'] ?? false),
            Wire::str($wire['declaredBy'] ?? null),
            Wire::str($wire['declaredAt'] ?? null),
            Wire::nullableStr($wire['agreelyIdentity'] ?? null),
        );
    }
}
