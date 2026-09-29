<?php

declare(strict_types=1);

namespace Agreely\Sdk;

use Agreely\Sdk\Errors\AgreelyConfigError;

/**
 * The Idempotency-Key of a consent write (manual record, verbal record, verbal
 * paper): the caller's own key when given, checked against the server's rule, else
 * one generated per call.
 *
 * On these endpoints the server binds the key to the ENDPOINT and to the REQUEST
 * BODY: the same key with a different body is a NEW request, and a key spent on one
 * endpoint never replays another endpoint's answer. It must be 1 to 255 printable
 * ASCII characters, or the server answers 422; the SDK refuses it before the call.
 */
final class IdempotencyKey
{
    private const PATTERN = '/^[\x21-\x7E]{1,255}$/D';

    private function __construct()
    {
    }

    /**
     * @param array<string,mixed> $options
     */
    public static function resolve(array $options, string $label): string
    {
        if (!array_key_exists('idempotencyKey', $options) || $options['idempotencyKey'] === null) {
            return self::generate();
        }
        $key = $options['idempotencyKey'];
        if (!is_string($key) || preg_match(self::PATTERN, $key) !== 1) {
            throw new AgreelyConfigError(
                "{$label}: \"idempotencyKey\" must be 1 to 255 printable ASCII characters (no space, no control "
                . 'character).',
            );
        }
        return $key;
    }

    /** A unique key (a v4-style uuid behind an idem_ prefix). */
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            'idem_%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
