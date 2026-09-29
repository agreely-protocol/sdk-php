<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The capacity in which a REPRESENTATIVE answered a verbal consent call
 * (respondent.representativeCapacity). Required for a representative and only for
 * one; UNDECLARED is an honest answer.
 *
 * The last two name who consents for a MINOR under 14 (P-39.1 s. 14 para. 2; A-2.1
 * s. 53.1 para. 2) and are never interchangeable with the adult measures: with
 * isMinor, the respondent must be a representative in one of them, named in
 * respondent.name.
 */
final class RepresentativeCapacity
{
    public const TUTELLE                      = 'tutelle';
    public const MANDAT_PROTECTION_HOMOLOGUE  = 'mandat_protection_homologue';
    public const REPRESENTATION_TEMPORAIRE    = 'representation_temporaire';
    public const CURATELLE                    = 'curatelle';
    public const OTHER                        = 'other';
    public const UNDECLARED                   = 'undeclared';
    public const TITULAIRE_AUTORITE_PARENTALE = 'titulaire_autorite_parentale';
    public const TUTEUR_MINEUR                = 'tuteur_mineur';

    /** Every capacity openapi.yaml declares, in spec order. */
    public const ALL = [
        self::TUTELLE,
        self::MANDAT_PROTECTION_HOMOLOGUE,
        self::REPRESENTATION_TEMPORAIRE,
        self::CURATELLE,
        self::OTHER,
        self::UNDECLARED,
        self::TITULAIRE_AUTORITE_PARENTALE,
        self::TUTEUR_MINEUR,
    ];

    /** The two capacities that consent for a minor under 14. */
    public const FOR_MINOR = [self::TITULAIRE_AUTORITE_PARENTALE, self::TUTEUR_MINEUR];

    private function __construct()
    {
    }
}
