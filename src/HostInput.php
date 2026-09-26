<?php

declare(strict_types=1);

namespace Agreely\Sdk;

use Agreely\Sdk\Errors\AgreelyConfigError;

/**
 * The client-side guards the host-retention and inventory surfaces need, ported
 * from the TS SDK's util.ts. Pure, side-effect-free static helpers: every one of
 * them refuses BEFORE a wire call, so a cron discovers a bad slug or a stale
 * instant on the spot instead of as a 422 at 3am.
 *
 * The server stays the authority. These only save a round trip, and they are
 * deliberately the same rules the server enforces (see HostReportInput and
 * InventoryInput on the API side).
 */
final class HostInput
{
    /**
     * The `Idempotency-Key` header the declaration endpoints require: 1 to 255
     * printable ASCII characters.
     */
    private const IDEMPOTENCY_KEY = '/^[\x21-\x7E]{1,255}$/';

    /** A host slug: lowercase, dot, dash, underscore, at most 64 characters. */
    private const SLUG = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    /** RFC 3339 with an EXPLICIT offset, as the server requires. */
    private const RFC3339_WITH_OFFSET =
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    /** A calendar date, YYYY-MM-DD. */
    private const CALENDAR_DAY = '/^\d{4}-\d{2}-\d{2}$/';

    private function __construct()
    {
    }

    /**
     * A `hostSystem` or `hostCategory`: a slug naming a SYSTEM or a KIND of
     * records, never a record. No run of 5 digits (4 would refuse
     * "clients-2024") and no run of 8 or more hexadecimal characters containing a
     * digit (a uuid, a hash, a pod suffix).
     *
     * NEW VALUES ARE RATIONED SERVER-SIDE: at most 10 distinct `hostSystem`
     * values per organisation, and 20 distinct `hostCategory` values per rule,
     * may be introduced in any rolling 30 days. Past that a NEW value is a 422
     * `host_token_limit` (a value already reported is always accepted). So a pod
     * name, a container id, a hostname or a deployment slot as `hostSystem`
     * BURNS A SLOT PER DEPLOY and locks you out within days. Choose a stable name
     * once: "billing", "crm", "warehouse", never "billing-7f9c8d6b5-x2x4k".
     *
     * This guard catches the id-shaped values; it cannot catch a well-formed one
     * that still changes per deploy.
     */
    public static function hostToken(mixed $value, string $label): string
    {
        if (
            !is_string($value)
            || preg_match(self::SLUG, $value) !== 1
            || preg_match('/\d{5}/', $value) === 1
            || preg_match('/(?=[0-9a-f]*\d)[0-9a-f]{8,}/', $value) === 1
        ) {
            throw new AgreelyConfigError(
                "{$label} must name a system or a kind of records as a stable slug (lowercase letters, digits, "
                . '. _ -; at most 64 characters; no run of 5 digits; no hexadecimal identifier). A pod name, a '
                . 'container id or a record id is refused, and a value that changes per deploy burns one of the '
                . '10 host systems allowed per 30 days.',
            );
        }
        return $value;
    }

    /**
     * The replay key of a declaration, read from the $options argument because it
     * travels as the `Idempotency-Key` HEADER and never as a body member.
     *
     * Derive it from something DURABLE and unique to the operation (your job-run
     * id plus the rule key, say), never from a value generated per attempt: a key
     * regenerated after a crash is a second declaration, not a retry.
     *
     * ⚠️ THE REPLAY IS BOUND TO THE API KEY THAT DECLARED. Another API key of the
     * same company sending the same Idempotency-Key records its OWN declaration
     * and never reads the first one back. So replaying a declaration whose
     * response was lost, after ROTATING the API key, records a SECOND
     * declaration. Settle every pending declaration with the old key before you
     * revoke it.
     *
     * @param array<string,mixed> $options
     */
    public static function idempotencyKey(array $options, string $label): string
    {
        $key = $options['idempotencyKey'] ?? null;
        if (!is_string($key) || preg_match(self::IDEMPOTENCY_KEY, $key) !== 1) {
            throw new AgreelyConfigError(
                "{$label} requires an \"idempotencyKey\" option (sent as the Idempotency-Key header): 1 to 255 "
                . 'printable ASCII characters, derived from something durable such as your job-run id and the '
                . 'rule key.',
            );
        }
        return $key;
    }

    /**
     * An instant for the wire. A DateTimeInterface is sent in UTC; a string must
     * already carry an EXPLICIT offset, because a naive timestamp would be read in
     * the server's zone and silently re-date the evidence, so it is refused rather
     * than guessed.
     *
     * @return array{wire:string,ms:float} the wire string and its epoch milliseconds
     */
    public static function instant(mixed $value, string $label): array
    {
        if ($value instanceof \DateTimeInterface) {
            $utc = \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'));
            return ['wire' => $utc->format('Y-m-d\TH:i:s.v\Z'), 'ms' => self::epochMs($utc)];
        }
        if (is_string($value) && preg_match(self::RFC3339_WITH_OFFSET, $value) === 1) {
            try {
                return ['wire' => $value, 'ms' => self::epochMs(new \DateTimeImmutable($value))];
            } catch (\Exception) {
                // An offset-shaped string PHP still cannot read (month 19): fall through.
            }
        }
        throw new AgreelyConfigError(
            "{$label} must be a DateTimeInterface or an RFC 3339 date and time with an explicit offset, "
            . 'e.g. 2026-09-25T03:00:00-04:00.',
        );
    }

    /** A calendar DATE, YYYY-MM-DD: a day the purge covered, never an instant. */
    public static function calendarDay(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match(self::CALENDAR_DAY, $value) !== 1) {
            throw new AgreelyConfigError("{$label} must be a calendar date, YYYY-MM-DD.");
        }
        return $value;
    }

    /** A non-empty identifier a path segment is built from (a ruleKey, a statementKey). */
    public static function pathKey(mixed $value, string $method, string $name): string
    {
        $key = is_string($value) ? trim($value) : '';
        if ($key === '') {
            throw new AgreelyConfigError("{$method} requires a {$name}.");
        }
        return $key;
    }

    /**
     * A CLOSED input shape: every member outside $allowed is refused instead of
     * silently dropped. This is how the PHP SDK expresses what the TS SDK gets
     * from its types: an `idempotencyKey` cannot end up in a declaration body
     * (it is a header, and it belongs in the $options argument), and a typo in a
     * member name is a loud error rather than an omitted field.
     *
     * @param array<array-key,mixed> $input
     * @param list<string> $allowed
     */
    public static function closed(array $input, array $allowed, string $label): void
    {
        foreach (array_keys($input) as $member) {
            if (in_array($member, $allowed, true)) {
                continue;
            }
            $name = is_string($member) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $member) === 1
                ? $member
                : 'a member';
            if (is_string($member) && strcasecmp(str_replace('-', '', $member), 'idempotencykey') === 0) {
                throw new AgreelyConfigError(
                    "{$label}: the Idempotency-Key is a HEADER, not a body member. Pass it as the "
                    . '$options argument: [\'idempotencyKey\' => ...].',
                );
            }
            throw new AgreelyConfigError(
                "{$label}: {$name} is not a member of this input. Accepted members: "
                . implode(', ', $allowed) . '.',
            );
        }
    }

    /** Epoch milliseconds, as a float so a sub-second instant compares exactly. */
    private static function epochMs(\DateTimeImmutable $at): float
    {
        return (float) $at->format('U') * 1000.0 + (float) $at->format('v');
    }
}
