<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The DECLARED non-consent lawful basis behind a "necessity" allow: the `basis`
 * field of openapi.yaml CheckDecision / BatchDecision. Present ONLY when status is
 * {@see CheckStatus::NECESSITY}; absent on every other status.
 *
 * Agreely records the company's DECLARED basis and does NOT certify its legal
 * validity. A necessity allow carries no consent artifact and must never be shown
 * to a person or an auditor as "consented".
 *
 *   CONTRACT              art. 12 al. 2 (3 deg)
 *   NECESSARY_FOR_SERVICE art. 12 al. 2 (4 deg), supply of a product or service
 *   SECURITY_FRAUD        art. 12 al. 2 (3 deg) / art. 9 al. 1 (1 deg)
 *   LEGAL_OBLIGATION      art. 18 / art. 23
 *   PROFESSIONAL_CONTACT  art. 1 al. 5. NOTE: this is a SCOPE CARVE-OUT from Sections
 *                         II and III of the Act, not a necessity ground under art. 12
 *                         or art. 9. It appears here because it also produces a
 *                         necessity allow.
 *
 * These are the P-39.1 (private sector) bases, which is the whole vocabulary the /v1
 * check API emits today.
 */
final class CheckBasis
{
    public const CONTRACT              = 'contract';
    public const NECESSARY_FOR_SERVICE = 'necessary_for_service';
    public const SECURITY_FRAUD        = 'security_fraud';
    public const LEGAL_OBLIGATION      = 'legal_obligation';
    public const PROFESSIONAL_CONTACT  = 'professional_contact';

    /** Every basis openapi.yaml declares, in spec order. */
    public const ALL = [
        self::CONTRACT,
        self::NECESSARY_FOR_SERVICE,
        self::SECURITY_FRAUD,
        self::LEGAL_OBLIGATION,
        self::PROFESSIONAL_CONTACT,
    ];

    private function __construct()
    {
    }
}
