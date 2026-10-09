<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * What a host DECLARES it did with one customer's information once the relationship
 * ended. There is deliberately no « nothing applied »: when no disposition applies, the
 * honest register entry is no entry.
 */
final class DispositionKind
{
    public const DESTROYED  = 'destroyed';
    public const ANONYMIZED = 'anonymized';
    public const LEGAL_HOLD = 'legal_hold';

    /** The closed vocabulary. */
    public const ALL = [self::DESTROYED, self::ANONYMIZED, self::LEGAL_HOLD];

    private function __construct()
    {
    }
}
