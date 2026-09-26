<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

use Agreely\Sdk\Errors\AgreelyConfigError;

/**
 * The disposition a host DECLARES it applied: the PAST PARTICIPLE, and only the
 * two the acts know.
 *
 * 🔴 NOT {@see RetentionAction}, which is the rule's own `destroy` / `anonymize`.
 * A rule says what must happen; a declaration says what was done. Sending the
 * rule's word here is a 422 on every purge, so {@see PurgeMethod::assert()}
 * refuses it client-side and says which word was meant.
 *
 * ⚠️ THERE IS NO `aggregated`. Aggregation is a TECHNIQUE recorded on an
 * anonymisation process, not a third disposition, and the acts know only these
 * two.
 */
final class PurgeMethod
{
    public const DESTROYED  = 'destroyed';
    public const ANONYMIZED = 'anonymized';

    /** Every method openapi.yaml declares, in spec order. */
    public const ALL = [self::DESTROYED, self::ANONYMIZED];

    /**
     * The rule's own vocabulary, mapped to the declaration's, so a caller that
     * passed the wrong one is told which word it meant instead of being told it
     * is wrong.
     */
    private const FROM_RULE_ACTION = [
        RetentionAction::DESTROY   => self::DESTROYED,
        RetentionAction::ANONYMIZE => self::ANONYMIZED,
    ];

    private function __construct()
    {
    }

    /** Refuse anything that is not one of the two dispositions, before any wire call. */
    public static function assert(mixed $value, string $label): string
    {
        if ($value === self::DESTROYED || $value === self::ANONYMIZED) {
            return $value;
        }
        $hint = '';
        if (is_string($value) && isset(self::FROM_RULE_ACTION[$value])) {
            $hint = " \"{$value}\" is the RULE's own action (what must happen); a declaration says what WAS done, "
                . 'so the method you meant is "' . self::FROM_RULE_ACTION[$value] . '".';
        }
        throw new AgreelyConfigError(
            "{$label}: method must be \"" . self::DESTROYED . '" or "' . self::ANONYMIZED . '", the two '
            . 'dispositions the acts know. Aggregation is a technique recorded on an anonymisation process, '
            . 'not a third disposition.' . $hint,
        );
    }

    /**
     * The disposition that DECLARES a rule's action was carried out. Use it to
     * derive a purge's `method` from the rule you read, rather than retyping the
     * word: retention.declarePurge(..., ['method' => PurgeMethod::forRule($rule->action), ...]).
     */
    public static function forRule(string $ruleAction): string
    {
        if (!isset(self::FROM_RULE_ACTION[$ruleAction])) {
            throw new AgreelyConfigError(
                "PurgeMethod::forRule: \"{$ruleAction}\" is not a rule action. Pass a rule's own `action`, one of "
                . implode(' / ', RetentionAction::ALL) . '.',
            );
        }
        return self::FROM_RULE_ACTION[$ruleAction];
    }
}
