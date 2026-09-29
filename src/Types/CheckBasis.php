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
 * A public body (A-2.1) returns its own, DISJOINT set:
 *
 *   ATTRIBUTIONS / PROGRAMME / ENTENTE_COLLECTE  collected under art. 64, used under art. 65.1 al. 1
 *   COMPATIBLE_USE        art. 65.1 al. 2 (1 deg)
 *   MANIFEST_BENEFIT      art. 65.1 al. 2 (2 deg)
 *   LAW_APPLICATION       art. 65.1 al. 2 (3 deg)
 *   PUBLIC_CHARACTER      art. 55 al. 1 with art. 57, a SCOPE CARVE-OUT
 *
 * Read the tenant's regime (GET /v1/catalog) before switching on a basis.
 * depersonalized_research is deliberately absent: it never produces an allow at the
 * gate (see {@see CheckStatus::REQUIRES_DEPERSONALIZATION}).
 */
final class CheckBasis
{
    public const CONTRACT              = 'contract';
    public const NECESSARY_FOR_SERVICE = 'necessary_for_service';
    public const SECURITY_FRAUD        = 'security_fraud';
    public const LEGAL_OBLIGATION      = 'legal_obligation';
    public const PROFESSIONAL_CONTACT  = 'professional_contact';
    public const ATTRIBUTIONS          = 'attributions';
    public const PROGRAMME             = 'programme';
    public const ENTENTE_COLLECTE      = 'entente_collecte';
    public const COMPATIBLE_USE        = 'compatible_use';
    public const MANIFEST_BENEFIT      = 'manifest_benefit';
    public const LAW_APPLICATION       = 'law_application';
    public const PUBLIC_CHARACTER      = 'public_character';

    /** The private-sector (P-39.1) bases. */
    public const PRIVATE = [
        self::CONTRACT,
        self::NECESSARY_FOR_SERVICE,
        self::SECURITY_FRAUD,
        self::LEGAL_OBLIGATION,
        self::PROFESSIONAL_CONTACT,
    ];

    /** The public-body (A-2.1) bases. */
    public const PUBLIC = [
        self::ATTRIBUTIONS,
        self::PROGRAMME,
        self::ENTENTE_COLLECTE,
        self::COMPATIBLE_USE,
        self::MANIFEST_BENEFIT,
        self::LAW_APPLICATION,
        self::PUBLIC_CHARACTER,
    ];

    /** Every basis openapi.yaml declares, in spec order. */
    public const ALL = [
        self::CONTRACT,
        self::NECESSARY_FOR_SERVICE,
        self::SECURITY_FRAUD,
        self::LEGAL_OBLIGATION,
        self::PROFESSIONAL_CONTACT,
        self::ATTRIBUTIONS,
        self::PROGRAMME,
        self::ENTENTE_COLLECTE,
        self::COMPATIBLE_USE,
        self::MANIFEST_BENEFIT,
        self::LAW_APPLICATION,
        self::PUBLIC_CHARACTER,
    ];

    private function __construct()
    {
    }
}
