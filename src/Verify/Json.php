<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

use stdClass;

/**
 * How the verifier reads a decoded JSON value (ReceiptVerifier::exactJson): a JSON object is a string-keyed array, or a
 * stdClass when an array cannot hold it as one ({} or keys "0".."n-1"); a JSON array is a list.
 *
 * @internal
 */
final class Json
{
    /** A JSON object. In an array decoded by the caller, {} is [], so [] counts as one. */
    public static function isObject(mixed $v): bool
    {
        return $v instanceof stdClass || (is_array($v) && ($v === [] || !array_is_list($v)));
    }

    /** @return array<array-key,mixed> */
    public static function obj(mixed $v): array
    {
        if ($v instanceof stdClass) {
            return (array) $v;
        }
        return is_array($v) && !array_is_list($v) ? $v : [];
    }

    /** @return list<mixed> */
    public static function arr(mixed $v): array
    {
        return is_array($v) && array_is_list($v) ? $v : [];
    }

    public static function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    public static function has(mixed $o, string $key): bool
    {
        return self::isObject($o) && array_key_exists($key, self::obj($o));
    }

    /**
     * Every member name of the object is one of $allowed.
     *
     * @param list<string> $allowed
     */
    public static function onlyKeys(mixed $o, array $allowed): bool
    {
        foreach (array_keys(self::obj($o)) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                return false;
            }
        }
        return true;
    }
}
