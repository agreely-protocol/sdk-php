<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * One purpose of a verbal consent's history (GET /v1/verbal-consents/{consentId}).
 *
 *   answer      "yes" | "no" | "informed". "informed" is a line the document gave
 *               for information, acknowledged from the script and never answered.
 *   consentRef  the cell's handle, null for a "no" (no cell was recorded)
 *   status      the cell's state: active | revoked | expired | erased, or null
 *   paper       what the signed paper said: confirmed_on_paper |
 *               withdrawn_on_paper, or null (no paper, or not on it)
 *   confirmedBy the manual cell that confirmed it ({consentRef, status}), or null.
 *               Once confirmed, the gate reads THAT cell: withdraw or erase it, not
 *               the verbal one.
 */
final class VerbalPurpose
{
    public const ANSWER_YES      = 'yes';
    public const ANSWER_NO       = 'no';
    public const ANSWER_INFORMED = 'informed';

    public const PAPER_CONFIRMED = 'confirmed_on_paper';
    public const PAPER_WITHDRAWN = 'withdrawn_on_paper';

    /**
     * @param array{consentRef:string,status:string}|null $confirmedBy
     */
    public function __construct(
        public readonly ?string $category,
        public readonly ?string $purpose,
        public readonly string $answer,
        public readonly ?string $consentRef,
        public readonly ?string $status,
        public readonly ?string $paper,
        public readonly ?array $confirmedBy,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $by = $wire['confirmedBy'] ?? null;
        return new self(
            Wire::nullableStr($wire['category'] ?? null),
            Wire::nullableStr($wire['purpose'] ?? null),
            Wire::str($wire['answer'] ?? null),
            Wire::nullableStr($wire['consentRef'] ?? null),
            Wire::nullableStr($wire['status'] ?? null),
            Wire::nullableStr($wire['paper'] ?? null),
            is_array($by)
                ? ['consentRef' => Wire::str($by['consentRef'] ?? null), 'status' => Wire::str($by['status'] ?? null)]
                : null,
        );
    }
}
