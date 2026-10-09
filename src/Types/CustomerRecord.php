<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * What Agreely's customer registry holds for ONE customer reference, as METADATA
 * (PUT and GET /v1/customers/{customerRef}, scope 'registry').
 *
 * ⚠️ EVERY PERSONAL FIELD IS A BOOLEAN. `hasDisplayName`, `hasEmail` and `hasBasisNote`
 * say WHETHER a value is held, never the value: a per-reference read that returned an
 * address would be a bulk export with a for-loop around it. Agreely is the place the
 * accountability record lives, not a place to read your customers back from. The two
 * values returned are the ones you DECLARED and which identify nobody: `legalBasis`
 * (the statutory vocabulary) and `noticeLocale` ("fr", "en" or null).
 *
 *   registered  whether an identity row is held at all. False for a reference Agreely
 *               knows only from consent history.
 *   source      the FIRST entry route: manual | import | api | ceremony (null when not registered)
 *   created     on upsert(): true when this call created the identity row (201), false
 *               when it merged into an existing one (200). Null on get().
 */
final class CustomerRecord
{
    public function __construct(
        public readonly string $customerRef,
        public readonly bool $registered,
        public readonly ?string $source,
        public readonly bool $hasDisplayName,
        public readonly bool $hasEmail,
        public readonly bool $hasBasisNote,
        public readonly ?string $legalBasis,
        public readonly ?string $noticeLocale,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly RelationshipState $relationship,
        public readonly ?bool $created = null,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire, ?bool $created = null): self
    {
        return new self(
            Wire::str($wire['customerRef'] ?? null),
            Wire::bool($wire['registered'] ?? false),
            Wire::nullableStr($wire['source'] ?? null),
            Wire::bool($wire['hasDisplayName'] ?? false),
            Wire::bool($wire['hasEmail'] ?? false),
            Wire::bool($wire['hasBasisNote'] ?? false),
            Wire::nullableStr($wire['legalBasis'] ?? null),
            Wire::nullableStr($wire['noticeLocale'] ?? null),
            Wire::nullableStr($wire['createdAt'] ?? null),
            Wire::nullableStr($wire['updatedAt'] ?? null),
            RelationshipState::fromWire(Wire::object($wire, 'relationship')),
            $created,
        );
    }
}
