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
 *
 * Not final: {@see UpsertCustomerResult} extends it with `created`, as the TypeScript
 * twin's interface does.
 */
class CustomerRecord
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
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        return new self(...self::wireArgs($wire));
    }

    /**
     * The record's members, read off the wire, as the named arguments of the
     * constructor, so a subclass reads them the same way.
     *
     * @param array<string,mixed> $wire
     * @return array{customerRef:string,registered:bool,source:?string,hasDisplayName:bool,hasEmail:bool,hasBasisNote:bool,legalBasis:?string,noticeLocale:?string,createdAt:?string,updatedAt:?string,relationship:RelationshipState}
     */
    protected static function wireArgs(array $wire): array
    {
        return [
            'customerRef' => Wire::str($wire['customerRef'] ?? null),
            'registered' => Wire::bool($wire['registered'] ?? false),
            'source' => Wire::nullableStr($wire['source'] ?? null),
            'hasDisplayName' => Wire::bool($wire['hasDisplayName'] ?? false),
            'hasEmail' => Wire::bool($wire['hasEmail'] ?? false),
            'hasBasisNote' => Wire::bool($wire['hasBasisNote'] ?? false),
            'legalBasis' => Wire::nullableStr($wire['legalBasis'] ?? null),
            'noticeLocale' => Wire::nullableStr($wire['noticeLocale'] ?? null),
            'createdAt' => Wire::nullableStr($wire['createdAt'] ?? null),
            'updatedAt' => Wire::nullableStr($wire['updatedAt'] ?? null),
            'relationship' => RelationshipState::fromWire(Wire::object($wire, 'relationship')),
        ];
    }
}
