<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The action a RULE sets: the INFINITIVE. What the organisation decided must
 * happen to a holding when its period elapses.
 *
 * 🔴 NOT {@see PurgeMethod}, and the two are deliberately separate classes so a
 * constant from one cannot be mistaken for a constant from the other. The rule
 * says `destroy`; a declaration of what a host DID says `destroyed`. Sending a
 * rule's own word as a purge's `method` is a 422 on every purge, which is exactly
 * the mistake these two classes exist to make impossible to write by accident.
 */
final class RetentionAction
{
    public const DESTROY   = 'destroy';
    public const ANONYMIZE = 'anonymize';

    /** Every action openapi.yaml declares, in spec order. */
    public const ALL = [self::DESTROY, self::ANONYMIZE];

    private function __construct()
    {
    }
}
