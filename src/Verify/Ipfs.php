<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

use Agreely\Sdk\Crypto\Multibase;
use Closure;
use Throwable;

/**
 * The IPFS copy of a consent document, fetched from a list of gateways that are trusted for nothing: a gateway's bytes
 * count only once they re-encode to the CID (public/assets/verify/issuer.js cidV0OfFile, fetchFromGateway). Vector:
 * vectors/ipfs-gateways.json.
 *
 * A gateway is a string (a prefix to which the CID is appended, or a template carrying `{cid}`) or a Closure (or any
 * other non-string callable) of the CID. A string is always a URL, even one that names a PHP function.
 */
final class Ipfs
{
    /**
     * The gateways asked when the caller names none, in order: the list vectors/ipfs-gateways.json freezes, whose
     * `measured` member records how it was chosen.
     */
    public const DEFAULT_GATEWAYS = ['https://gateway.lighthouse.storage/ipfs/'];

    /** The largest file `ipfs add` keeps in ONE block (the default 256 KiB chunk), so its CID can be recomputed. */
    public const MAX_SINGLE_BLOCK_BYTES = 262144;

    private const BASE32 = 'abcdefghijklmnopqrstuvwxyz234567';
    private const CODEC_RAW = 0x55;
    private const CODEC_DAG_PB = 0x70;

    /** A gateway a caller passes: a string is a URL, anything else callable is a function of the CID. */
    public static function isGateway(mixed $gateway): bool
    {
        return is_string($gateway) || $gateway instanceof Closure || is_callable($gateway);
    }

    /** Can this verifier tell whether bytes are the file of `$cid`? A CIDv0, or a base32 CIDv1 (raw or dag-pb) over sha2-256. */
    public static function cidIsComputable(string $cid): bool
    {
        return self::cidTarget($cid) !== null;
    }

    /**
     * Do these bytes re-encode to `$cid`? True or false for a computable CID; null when the CID is not one this verifier
     * can recompute. A dag-pb CID is recomputed for a file of at most one block.
     */
    public static function bytesMatchCid(string $cid, string $bytes): ?bool
    {
        $target = self::cidTarget($cid);
        if ($target === null) {
            return null;
        }
        if ($target['of'] === 'node' && strlen($bytes) > self::MAX_SINGLE_BLOCK_BYTES) {
            return false;
        }
        $digest = hash('sha256', $target['of'] === 'node' ? self::singleBlockNode($bytes) : $bytes, true);
        return hash_equals($target['digest'], $digest);
    }

    /** The URL a gateway gives for `$cid`. Throws when a function gateway throws or gives no string. */
    public static function gatewayUrl(mixed $gateway, string $cid): string
    {
        if (!is_string($gateway)) {
            if (!is_callable($gateway)) {
                throw new \InvalidArgumentException('not a gateway');
            }
            $url = $gateway($cid);
            if (!is_string($url)) {
                throw new \UnexpectedValueException('the gateway function gave no URL');
            }
            return $url;
        }
        $at = strpos($gateway, '{cid}');
        return $at !== false ? substr_replace($gateway, $cid, $at, strlen('{cid}')) : rtrim($gateway, '/') . '/' . $cid;
    }

    /** How a gateway is named in a note and in the result: as configured, or the origin of a function's URL. */
    public static function gatewayName(mixed $gateway, string $url): string
    {
        if (is_string($gateway)) {
            return $gateway;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $scheme = strtolower($parts['scheme']);
        $defaults = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443, 'ftp' => 21];
        if (!array_key_exists($scheme, $defaults)) {
            return 'null';
        }
        $port = isset($parts['port']) && $parts['port'] !== $defaults[$scheme] ? ':' . $parts['port'] : '';
        return $scheme . '://' . strtolower($parts['host']) . $port;
    }

    /**
     * Ask each gateway in order for the file of `$cid`. A gateway delivers when its bytes re-encode to the CID: `$judge`
     * then decides, and no further gateway is asked. An HTTP or network error, a body over one block, or bytes that do
     * not re-encode move to the next gateway. When the CID cannot be recomputed, only bytes `$judge` accepts are
     * believed (`$accepts`): a hash that matches vouches for itself, a mismatch proves nothing about the pinned file.
     * TRAP: never send `Accept: application/json`; a gateway may answer it with a DAG-JSON rendering of the node.
     *
     * @template J
     * @param list<mixed> $gateways
     * @param callable(string): array{body:?string, status:?int} $get status is set on an HTTP error, body null on a
     *        network error; a body may be cut at MAX_SINGLE_BLOCK_BYTES + 1
     * @param callable(string): J $judge
     * @param callable(J): bool $accepts
     * @return array{delivered:true, gateway:string, verdict:J}|array{delivered:false, attempts:list<array{gateway:string, failure:string}>}
     */
    public static function readPinnedFile(string $cid, array $gateways, callable $get, callable $judge, callable $accepts): array
    {
        $computable = self::cidIsComputable($cid);
        $attempts = [];
        foreach ($gateways as $gateway) {
            try {
                $url = self::gatewayUrl($gateway, $cid);
            } catch (Throwable) {
                $attempts[] = ['gateway' => is_string($gateway) ? $gateway : 'the gateway function', 'failure' => 'it gave no URL'];
                continue;
            }
            $name = self::gatewayName($gateway, $url);
            try {
                $answer = $get($url);
            } catch (Throwable) {
                $answer = ['body' => null, 'status' => null];
            }
            if ($answer['status'] !== null) {
                $attempts[] = ['gateway' => $name, 'failure' => "HTTP {$answer['status']}"];
                continue;
            }
            $bytes = $answer['body'];
            if ($bytes === null) {
                $attempts[] = ['gateway' => $name, 'failure' => 'no answer (network error or timeout)'];
                continue;
            }
            if (strlen($bytes) > self::MAX_SINGLE_BLOCK_BYTES) {
                $attempts[] = ['gateway' => $name, 'failure' => 'a body over ' . self::MAX_SINGLE_BLOCK_BYTES . ' bytes'];
                continue;
            }
            if ($computable) {
                if (self::bytesMatchCid($cid, $bytes) !== true) {
                    $attempts[] = ['gateway' => $name, 'failure' => 'bytes that do not re-encode to the CID'];
                    continue;
                }
                return ['delivered' => true, 'gateway' => $name, 'verdict' => $judge($bytes)];
            }
            $verdict = $judge($bytes);
            if ($accepts($verdict)) {
                return ['delivered' => true, 'gateway' => $name, 'verdict' => $verdict];
            }
            $attempts[] = [
                'gateway' => $name,
                'failure' => 'bytes that do not match the signed hash, under a CID this verifier cannot recompute',
            ];
        }
        return ['delivered' => false, 'attempts' => $attempts];
    }

    /**
     * The sha2-256 digest a CID names, and what it is the digest of; null when this verifier cannot recompute it.
     *
     * @return array{digest:string, of:'node'|'raw'}|null
     */
    private static function cidTarget(string $cid): ?array
    {
        if (preg_match('/^Qm[1-9A-HJ-NP-Za-km-z]{44}$/D', $cid) === 1) {
            try {
                $mh = Multibase::decodeBytes('z' . $cid);
            } catch (Throwable) {
                return null;
            }
            return strlen($mh) === 34 && $mh[0] === "\x12" && $mh[1] === "\x20" ? ['digest' => substr($mh, 2), 'of' => 'node'] : null;
        }
        if (!str_starts_with($cid, 'b')) {
            return null;
        }
        $b = self::base32Decode(substr($cid, 1));
        // version 1, a one-byte codec, then sha2-256 (0x12) over 32 bytes (0x20)
        if ($b === null || strlen($b) !== 36 || ord($b[0]) !== 0x01 || ord($b[2]) !== 0x12 || ord($b[3]) !== 0x20) {
            return null;
        }
        if (ord($b[1]) === self::CODEC_DAG_PB) {
            return ['digest' => substr($b, 4), 'of' => 'node'];
        }
        if (ord($b[1]) === self::CODEC_RAW) {
            return ['digest' => substr($b, 4), 'of' => 'raw'];
        }
        return null;
    }

    /** The dag-pb node `ipfs add` writes for a file of one block: its UnixFS File data is the whole file. */
    private static function singleBlockNode(string $bytes): string
    {
        $size = self::varint(strlen($bytes));
        $unixfs = "\x08\x02\x12" . $size . $bytes . "\x18" . $size;
        return "\x0a" . self::varint(strlen($unixfs)) . $unixfs;
    }

    /** @param int<0, max> $n */
    private static function varint(int $n): string
    {
        $out = '';
        while ($n >= 0x80) {
            $out .= chr(($n & 0x7f) | 0x80);
            $n >>= 7;
        }
        return $out . chr($n);
    }

    /** RFC 4648 base32, lowercase, no padding; trailing bits that do not fill a byte are dropped. Null on a foreign character. */
    private static function base32Decode(string $s): ?string
    {
        $bits = 0;
        $value = 0;
        $out = '';
        foreach (str_split($s) as $ch) {
            $i = strpos(self::BASE32, $ch);
            if ($i === false) {
                return null;
            }
            $value = (($value << 5) | $i) & 0xffff;
            $bits += 5;
            if ($bits >= 8) {
                $out .= chr(($value >> ($bits - 8)) & 0xff);
                $bits -= 8;
            }
        }
        return $out;
    }
}
