<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Support;

use Agreely\Sdk\Verify\ReceiptVerification;
use PHPUnit\Framework\Assert;
use UnexpectedValueException;

/**
 * The expectation of a chain vector case (citizen-key-chain.json, recorded-v4.json, anchor-batch.json), read field by
 * field: the keys the vector files name for what is not a plain result field.
 */
final class VectorCase
{
    /** Keys a test asserts against the chain stub, not against the result. */
    private const STUB_KEYS = ['readRefs', 'getLogsCalls', 'tried'];

    /** @return array<string,mixed> */
    public static function read(string $rel): array
    {
        /** @var array<string,mixed> $value */
        $value = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/vectors/{$rel}"), true, 512, JSON_THROW_ON_ERROR);
        return $value;
    }

    /**
     * The cases of one section of a vector file, keyed by name, as a data provider.
     *
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function cases(string $file, string $section = 'cases'): array
    {
        $out = [];
        foreach ((array) (self::read($file)[$section] ?? []) as $case) {
            $case = self::map($case);
            $out[self::str($case['name'] ?? null)] = [$case];
        }
        return $out;
    }

    public static function str(mixed $v): string
    {
        if (!is_string($v)) {
            throw new UnexpectedValueException('a vector member is not a string');
        }
        return $v;
    }

    /** An int, or the decimal string of one. */
    public static function int(mixed $v): int
    {
        if (is_int($v)) {
            return $v;
        }
        if (!is_string($v) || preg_match('/^-?\d+$/D', $v) !== 1) {
            throw new UnexpectedValueException('a vector member is not an integer');
        }
        return (int) $v;
    }

    /** @return array<string,mixed> */
    public static function map(mixed $v): array
    {
        if (!is_array($v)) {
            throw new UnexpectedValueException('a vector member is not an object');
        }
        $out = [];
        foreach ($v as $key => $member) {
            $out[(string) $key] = $member;
        }
        return $out;
    }

    /** @param array<array-key,mixed> $want */
    public static function expectFields(ReceiptVerification $result, array $want): void
    {
        $fields = $result->toArray();
        foreach ($want as $key => $value) {
            if ($key === 'absent') {
                foreach ((array) $value as $field) {
                    Assert::assertArrayNotHasKey(self::str($field), $fields, self::str($field) . ' is absent');
                }
            } elseif ($key === 'companyKeyVerdict') {
                Assert::assertSame($value, $result->companyKey['verdict'] ?? null, $key);
            } elseif ($key === 'companyKeyRotated') {
                Assert::assertSame($value, $result->companyKey['rotated'] ?? null, $key);
            } elseif ($key === 'signedAnchorBinding') {
                Assert::assertSame($value, $result->signedAnchor['binding'] ?? null, $key);
            } elseif ($key === 'citizenKey') {
                Assert::assertIsArray($result->citizenKey, $key);
                foreach ((array) $value as $member => $expected) {
                    Assert::assertSame($expected, $result->citizenKey[$member] ?? null, "citizenKey.{$member}");
                }
            } elseif ($key === 'counts') {
                $counts = $result->consentAnchorCounts ?? [];
                $expected = (array) $value;
                ksort($counts);
                ksort($expected);
                Assert::assertSame($expected, $counts, $key);
            } elseif (!in_array($key, self::STUB_KEYS, true)) {
                Assert::assertSame($value, $fields[$key] ?? null, (string) $key);
            }
        }
    }
}
