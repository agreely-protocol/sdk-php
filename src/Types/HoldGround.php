<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The two grounds of a retention hold:
 *
 *   RIGHTS_REQUEST  the information is the subject of an access or rectification request
 *                   (P-39.1 s. 36 privately, A-2.1 s. 102.1 for a public body)
 *   OTHER_LAW       another law requires it to be kept; the provision is required
 */
final class HoldGround
{
    public const RIGHTS_REQUEST = 'rights_request';
    public const OTHER_LAW      = 'other_law';

    /** The closed vocabulary. */
    public const ALL = [self::RIGHTS_REQUEST, self::OTHER_LAW];

    private function __construct()
    {
    }
}
