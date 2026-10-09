<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The 201 body of POST /v1/customers/{customerRef}/consent-sheets: the signature sheet
 * to print, the reference printed on it, and the claim minted with it.
 *
 * RETURNED ONCE. `printedReference` and `claim->token` are kept by Agreely only as
 * digests, so no later call can recover them: deliver them now or mint a new sheet.
 *
 * 🔴 Deliver `claim` (the claim link) through a DIFFERENT channel from the sheet, never
 * in the same envelope or message: the printed reference is the second factor that
 * defends a link travelling separately. On its own it opens nothing.
 */
final class ConsentSheet
{
    public function __construct(
        public readonly SignatureSheet $signatureSheet,
        public readonly string $printedReference,
        public readonly ClaimLink $claim,
    ) {
    }

    /** @param array<string,mixed> $wire */
    public static function fromWire(array $wire): self
    {
        $sheet = $wire['signatureSheet'] ?? null;
        /** @var array<string,mixed> $sheet */
        $sheet = is_array($sheet) ? $sheet : [];
        $claim = $wire['claim'] ?? null;
        /** @var array<string,mixed> $claim */
        $claim = is_array($claim) ? $claim : [];
        return new self(
            SignatureSheet::fromWire($sheet),
            Wire::str($wire['printedReference'] ?? null),
            ClaimLink::fromWire($claim),
        );
    }
}
