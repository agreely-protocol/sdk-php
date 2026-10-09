<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * Where a customer relationship stands (art. 23 / A-2.1 art. 73). The absence of any
 * lifecycle record reads as ACTIVE.
 */
final class RelationshipLifecycle
{
    public const ACTIVE = 'active';
    public const ENDING = 'ending';
    public const ENDED  = 'ended';

    /** The closed vocabulary. */
    public const ALL = [self::ACTIVE, self::ENDING, self::ENDED];

    private function __construct()
    {
    }
}
