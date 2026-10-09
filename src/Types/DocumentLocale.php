<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/** The language of a printed document or a published text: French or English, nothing else. */
final class DocumentLocale
{
    public const FR = 'fr';
    public const EN = 'en';

    /** The closed vocabulary. */
    public const ALL = [self::FR, self::EN];

    private function __construct()
    {
    }
}
