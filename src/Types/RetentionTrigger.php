<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * What starts a retention rule's clock: the `trigger` of openapi.yaml
 * RetentionRule. The host reads it and applies it; it never picks one.
 *
 * FORWARD COMPATIBILITY: the server may add triggers. A rule whose trigger you do
 * not recognise still carries a decided duration, so do not treat an unknown
 * value as a reason to purge on a guess.
 */
final class RetentionTrigger
{
    public const COLLECTION       = 'collection';
    public const PURPOSE_ACHIEVED = 'purpose_achieved';
    public const LAST_ACTIVITY    = 'last_activity';
    public const DECISION         = 'decision';

    /** Every trigger openapi.yaml declares, in spec order. */
    public const ALL = [
        self::COLLECTION,
        self::PURPOSE_ACHIEVED,
        self::LAST_ACTIVITY,
        self::DECISION,
    ];

    private function __construct()
    {
    }
}
