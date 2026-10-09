<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One host-DECLARED disposition of a customer's information, as METADATA: the outcome,
 * when, by which key, until when. The justification and the basis note are reported as
 * booleans (`hasReason`, `hasBasisNote`), never as text.
 *
 *   disposition      a {@see DispositionKind}: "destroyed" | "anonymized" | "legal_hold"
 *   retentionUntil   the declared end of a legal-hold conservation; null on a disposal
 *   endedAtSnapshot  the FROZEN end of the relationship the declaration was made against
 *   scheduleRef      the conservation rule you named, echoed verbatim and never verified
 *   basis            the frozen declared ground CODE the client was held on
 *   agreelyIdentity  what it did to Agreely's own copy of the identity ({@see AgreelyIdentityOutcome})
 *
 * ⚠️ DECLARED, NEVER VERIFIED. Agreely observed nothing: the information lives in your
 * systems, and it records who declared what and when.
 *
 * Not final: {@see DeclaredDisposition} extends it, as the TypeScript twin's interface does.
 * It is extended by the SDK's own results, not an extension point: do not subclass it.
 */
class RetentionDispositionRecord
{
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
        return new self(...self::wireArgs($wire));
    }

    /**
     * The record's own members, read off the wire, as the named arguments of the
     * constructor, so a subclass reads them the same way.
     *
     * @param array<string,mixed> $wire
     * @return array{id:string,disposition:string,retentionUntil:?string,hasReason:bool,endedAtSnapshot:?string,scheduleRef:?string,basis:?string,hasBasisNote:bool,declaredBy:string,declaredAt:string,agreelyIdentity:?string}
     */
    protected static function wireArgs(array $wire): array
    {
        return [
            'id' => Wire::str($wire['id'] ?? null),
            'disposition' => Wire::str($wire['disposition'] ?? null),
            'retentionUntil' => Wire::nullableStr($wire['retentionUntil'] ?? null),
            'hasReason' => Wire::bool($wire['hasReason'] ?? false),
            'endedAtSnapshot' => Wire::nullableStr($wire['endedAtSnapshot'] ?? null),
            'scheduleRef' => Wire::nullableStr($wire['scheduleRef'] ?? null),
            'basis' => Wire::nullableStr($wire['basis'] ?? null),
            'hasBasisNote' => Wire::bool($wire['hasBasisNote'] ?? false),
            'declaredBy' => Wire::str($wire['declaredBy'] ?? null),
            'declaredAt' => Wire::str($wire['declaredAt'] ?? null),
            'agreelyIdentity' => Wire::nullableStr($wire['agreelyIdentity'] ?? null),
        ];
    }
}
