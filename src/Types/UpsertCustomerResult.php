<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The answer to customers()->upsert(): the registry metadata (every
 * {@see CustomerRecord} member sits directly on it), and `created`, true when this call
 * created the identity record (201), false when it merged into an existing one (200).
 */
final class UpsertCustomerResult extends CustomerRecord
{
    public function __construct(
        string $customerRef,
        bool $registered,
        ?string $source,
        bool $hasDisplayName,
        bool $hasEmail,
        bool $hasBasisNote,
        ?string $legalBasis,
        ?string $noticeLocale,
        ?string $createdAt,
        ?string $updatedAt,
        RelationshipState $relationship,
        public readonly bool $created,
    ) {
        parent::__construct(
            $customerRef,
            $registered,
            $source,
            $hasDisplayName,
            $hasEmail,
            $hasBasisNote,
            $legalBasis,
            $noticeLocale,
            $createdAt,
            $updatedAt,
            $relationship,
        );
    }

    /** @param array<string,mixed> $wire */
    public static function fromWireCreated(array $wire, bool $created): self
    {
        return new self(...parent::wireArgs($wire), created: $created);
    }
}
