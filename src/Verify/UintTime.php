<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

/**
 * An on-chain time (a uint64 of unix seconds) as a canonical decimal string, so a value above PHP_INT_MAX stays exact
 * and never passes through a float. Only what a key window needs: read, compare, add.
 *
 * @internal
 */
final class UintTime
{
    private const HEX = '0123456789abcdef';

    /** A non-negative int or a string of decimal digits, as a canonical decimal string; null for anything else. */
    public static function of(mixed $v): ?string
    {
        if (is_int($v)) {
            return $v >= 0 ? (string) $v : null;
        }
        if (!is_string($v) || preg_match('/^[0-9]+$/D', $v) !== 1) {
            return null;
        }
        $digits = ltrim($v, '0');
        return $digits === '' ? '0' : $digits;
    }

    /** Hex digits (no prefix, any case) as a decimal string, exact at any length. */
    public static function fromHex(string $hex): string
    {
        $nibbles = [];
        foreach (str_split(strtolower($hex)) as $char) {
            $at = strpos(self::HEX, $char);
            if ($at === false) {
                throw new \InvalidArgumentException('not a hex digit');
            }
            $nibbles[] = $at;
        }
        $out = '';
        // Long division by 10 in base 16, one decimal digit per pass.
        while ($nibbles !== []) {
            $rest = 0;
            $quotient = [];
            foreach ($nibbles as $nibble) {
                $acc = $rest * 16 + $nibble;
                $digit = intdiv($acc, 10);
                $rest = $acc % 10;
                if ($quotient !== [] || $digit !== 0) {
                    $quotient[] = $digit;
                }
            }
            $out = $rest . $out;
            $nibbles = $quotient;
        }
        $out = ltrim($out, '0');
        return $out === '' ? '0' : $out;
    }

    /** -1, 0 or 1, as $a is below, equal to or above $b (both canonical). */
    public static function cmp(string $a, string $b): int
    {
        return strlen($a) === strlen($b) ? strcmp($a, $b) <=> 0 : strlen($a) <=> strlen($b);
    }

    /** $a + $b, both canonical. */
    public static function add(string $a, string $b): string
    {
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $carry = 0;
        $out = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry + ($i >= 0 ? ord($a[$i]) - 48 : 0) + ($j >= 0 ? ord($b[$j]) - 48 : 0);
            $out = chr(48 + $sum % 10) . $out;
            $carry = intdiv($sum, 10);
            $i--;
            $j--;
        }
        return $out === '' ? '0' : $out;
    }

    /** The value as an int when it fits one, else null. */
    public static function toInt(string $v): ?int
    {
        return self::cmp($v, (string) PHP_INT_MAX) <= 0 ? (int) $v : null;
    }
}
