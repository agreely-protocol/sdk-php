<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The honest proof behind a record-backed /v1/check answer: the `assurance` field of
 * CheckDecision / BatchDecision, the same wire value the receipts carry.
 *
 *   CITIZEN_SIGNED     the person signed with their own passkey (tier "full")
 *   COMPANY_ATTESTED   the company attests to a hand-signed paper (tier "manual")
 *   COMPANY_DOCUMENTED the person consented BY TELEPHONE and the organisation
 *                      documented it; there is no document at all (tier "verbal")
 *
 * The HOST decides what each one may unlock. A value this SDK does not know must be
 * treated as NOT acceptable: never widen an allow on an assurance you cannot read.
 */
final class Assurance
{
    public const CITIZEN_SIGNED     = 'citizen_signed';
    public const COMPANY_ATTESTED   = 'company_attested';
    public const COMPANY_DOCUMENTED = 'company_documented';

    /** Every assurance openapi.yaml declares, strongest first. */
    public const ALL = [
        self::CITIZEN_SIGNED,
        self::COMPANY_ATTESTED,
        self::COMPANY_DOCUMENTED,
    ];

    private function __construct()
    {
    }
}
