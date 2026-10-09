<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/** How the person asked for a withdrawal recorded on her behalf: the server's closed vocabulary. */
final class WithdrawalChannel
{
    public const PHONE     = 'phone';
    public const EMAIL     = 'email';
    public const MAIL      = 'mail';
    public const IN_PERSON = 'in_person';
    public const OTHER     = 'other';

    /** The closed vocabulary. */
    public const ALL = [self::PHONE, self::EMAIL, self::MAIL, self::IN_PERSON, self::OTHER];

    private function __construct()
    {
    }
}
