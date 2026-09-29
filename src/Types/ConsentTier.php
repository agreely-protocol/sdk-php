<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The proof tier of a consent under its stored name (full > manual > verbal): the
 * `tier` field of CheckDecision / BatchDecision, present exactly when `assurance` is.
 *
 *   FULL    passkey-signed by the person        (assurance citizen_signed)
 *   MANUAL  a hand-signed paper, company-attested (assurance company_attested)
 *   VERBAL  a telephone consent documented by the organisation (company_documented)
 *
 * A manual or verbal consent allows IDENTICALLY at the gate; only the tier differs,
 * and the host decides what each tier may unlock (for example "verbal" for one use,
 * "manual" for another). Neither P-39.1 s. 14 nor A-2.1 s. 53.1 requires a consent to
 * be in writing, so this is the host's choice of evidence, not a statutory threshold.
 * Treat an unknown tier as NOT acceptable.
 */
final class ConsentTier
{
    public const FULL   = 'full';
    public const MANUAL = 'manual';
    public const VERBAL = 'verbal';

    /** Every tier openapi.yaml declares, strongest first. */
    public const ALL = [self::FULL, self::MANUAL, self::VERBAL];

    private function __construct()
    {
    }

    /**
     * Whether $tier is at least $minimum (full > manual > verbal). An unknown or null
     * tier is never acceptable, whatever the minimum.
     */
    public static function atLeast(?string $tier, string $minimum): bool
    {
        $rank = array_flip(array_reverse(self::ALL));
        if ($tier === null || !isset($rank[$tier], $rank[$minimum])) {
            return false;
        }
        return $rank[$tier] >= $rank[$minimum];
    }
}
