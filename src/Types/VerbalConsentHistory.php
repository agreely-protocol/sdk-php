<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The history of a verbal consent (GET /v1/verbal-consents/{consentId}): what was
 * said on the telephone, what the paper said, and what is in force now.
 *
 * The verbal consent keeps tier "verbal" forever. `state` says where it is in its
 * life: STATE_AWAITING_PAPER (a paper is expected), STATE_VERBAL_ONLY (none is), or
 * STATE_PAPER_RECEIVED. `obtainedAt` (the call, the consent date) and `recordedAt`
 * (when Agreely recorded it) are kept apart on purpose, as are the paper's
 * `signedAt` (the evidence date) and its own `recordedAt`.
 *
 * `paper` is null until a paper came back; then it carries signedAt, recordedAt,
 * pdfSha256, manualConsentId, manualMerkleRoot and tier ("manual", or null when
 * every box came back unticked).
 */
final class VerbalConsentHistory
{
    public const STATE_AWAITING_PAPER = 'awaiting_paper';
    public const STATE_VERBAL_ONLY    = 'verbal_only';
    public const STATE_PAPER_RECEIVED = 'paper_received';

    /**
     * @param list<VerbalPurpose> $purposes
     * @param array{signedAt:string,recordedAt:string,pdfSha256:string,manualConsentId:?string,manualMerkleRoot:?string,tier:?string}|null $paper
     */
    public function __construct(
        public readonly string $consentId,
        public readonly string $tier,
        public readonly string $assurance,
        public readonly string $merkleRoot,
        public readonly bool $anchored,
        public readonly string $obtainedAt,
        public readonly string $recordedAt,
        public readonly ?string $obtainedBy,
        public readonly string $scriptVersion,
        public readonly string $documentVersionId,
        public readonly string $validUntil,
        public readonly bool $paperExpected,
        public readonly string $state,
        public readonly array $purposes,
        public readonly ?array $paper,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $paper = $wire['paper'] ?? null;
        return new self(
            Wire::str($wire['consentId'] ?? null),
            Wire::str($wire['tier'] ?? null),
            Wire::str($wire['assurance'] ?? null),
            Wire::str($wire['merkleRoot'] ?? null),
            Wire::bool($wire['anchored'] ?? false),
            Wire::str($wire['obtainedAt'] ?? null),
            Wire::str($wire['recordedAt'] ?? null),
            Wire::nullableStr($wire['obtainedBy'] ?? null),
            Wire::str($wire['scriptVersion'] ?? null),
            Wire::str($wire['documentVersionId'] ?? null),
            Wire::str($wire['validUntil'] ?? null),
            Wire::bool($wire['paperExpected'] ?? false),
            Wire::str($wire['state'] ?? null),
            array_map(VerbalPurpose::fromWire(...), Wire::objects($wire, 'purposes')),
            is_array($paper) ? [
                'signedAt' => Wire::str($paper['signedAt'] ?? null),
                'recordedAt' => Wire::str($paper['recordedAt'] ?? null),
                'pdfSha256' => Wire::str($paper['pdfSha256'] ?? null),
                'manualConsentId' => Wire::nullableStr($paper['manualConsentId'] ?? null),
                'manualMerkleRoot' => Wire::nullableStr($paper['manualMerkleRoot'] ?? null),
                'tier' => Wire::nullableStr($paper['tier'] ?? null),
            ] : null,
        );
    }
}
