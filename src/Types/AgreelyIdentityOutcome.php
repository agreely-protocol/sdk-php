<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * What a declaration or a hold release did to AGREELY'S OWN copy of the customer's
 * registry identity (the name, email, basis note and notice language):
 *
 *   ERASED         removed, irreversibly, in the same transaction. The reference, the
 *                  declared ground code, the declarations, the consent evidence and the
 *                  rights register remain; existing backups are not altered.
 *   NONE_HELD      nothing was held to remove (including after an earlier removal)
 *   RETAINED_HOLD  kept, because an active hold on ALL the information keeps it
 *   RETAINED       kept, because the declaration is a legal_hold
 *
 * Null when no destroyed or anonymized declaration stands, and on a declaration made
 * before the field existed. After ERASED, customers()->upsert() refuses a value for
 * those four fields (409 code identity_erased; a field may still be cleared); while a
 * hold keeps them it refuses any change (409 code identity_held).
 */
final class AgreelyIdentityOutcome
{
    public const ERASED        = 'erased';
    public const NONE_HELD     = 'none_held';
    public const RETAINED_HOLD = 'retained_hold';
    public const RETAINED      = 'retained';

    /** The closed vocabulary. */
    public const ALL = [self::ERASED, self::NONE_HELD, self::RETAINED_HOLD, self::RETAINED];

    private function __construct()
    {
    }
}
