<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

use Agreely\Sdk\Crypto\Keccak;
use InvalidArgumentException;

/**
 * The company's signing key on chain, as the web verifier reads it (public/assets/verify/issuer.js): the key's window
 * on the company's domain, companyKeyOf(keccak256(domain), sha256(raw Ed25519 key)), judged at the instant the key
 * signed, and getCompanyDid(keccak256(domain)) for the key the company holds today. Vector:
 * vectors/company-key-windows.json.
 *
 * Verdicts:
 *   authentic            in force at the signing instant
 *   key-not-authorised   not in force then: retired at or before it, or (notYetEffective) not valid yet
 *   signed-after-anchor  the signing instant is later than the consent anchor allows (anchoredAt + SKEW)
 *   pending              no window for this key on this domain
 *   voided               the window was voided: never authentic, whatever else it says
 *   recorded-late        declared in force then, but recorded more than LAG after it
 *   retired-issuer-dated in force then, but that instant is the issuer's own date and the key has since been retired
 *   unknown              unreadable
 */
final class CompanyKey
{
    /** keccak256("companyKeyOf(bytes32,bytes32)")[0..4]. */
    public const COMPANY_KEY_OF_SELECTOR = '0x25597a89';
    /** keccak256("getCompanyDid(bytes32)")[0..4]. */
    public const GET_COMPANY_DID_SELECTOR = '0x8181c629';

    private const MAX_SAFE_INTEGER = 9007199254740991;
    private const NOTES = [
        'authentic' => 'was in force on its domain at the instant it signed',
        'key-not-authorised' => 'was NOT in force on its domain at the instant it signed',
        'signed-after-anchor' => "signed later than the consent's own anchor allows, so the signing date is contradicted",
        'voided' => 'has a window the registry records as voided',
        'pending' => 'has no window on its domain in the registry yet',
        'recorded-late' => 'was recorded on its domain more than 72 hours after the instant it signed',
        'retired-issuer-dated' => "has since been retired, and the instant it signed is the issuer's own date, which no anchor bounds",
        'unknown' => 'has a window that cannot be judged at the instant it signed',
    ];

    /** The check a verdict reads as: only authentic passes; a key refused at the signing instant fails; anything else is not confirmed. */
    public static function statusOf(string $verdict): string
    {
        if ($verdict === 'authentic') {
            return 'pass';
        }
        return in_array($verdict, ['key-not-authorised', 'signed-after-anchor', 'voided'], true) ? 'fail' : 'unavailable';
    }

    /**
     * The clock a receipt's consent anchor gives: its time when it holds the receipt's trusted root, else the issuer's
     * own date.
     *
     * @param array<array-key,mixed>|null $anchor rootMatches and anchoredAt (unix seconds)
     * @return array{anchoredAt:?int, issuerDatedOnly:bool}
     */
    public static function receiptClockOf(?array $anchor): array
    {
        $at = $anchor['anchoredAt'] ?? null;
        return $anchor !== null && ($anchor['rootMatches'] ?? null) === true && is_int($at) && $at > 0
            ? ['anchoredAt' => $at, 'issuerDatedOnly' => false]
            : ['anchoredAt' => null, 'issuerDatedOnly' => true];
    }

    /**
     * The company domain the issuer DID document names: its first non-empty alsoKnownAs, the host of a did:web,
     * lowercased. Null when the document names none.
     */
    public static function domainFromDoc(mixed $doc): ?string
    {
        $first = null;
        foreach (Json::arr(Json::obj($doc)['alsoKnownAs'] ?? null) as $aka) {
            if (is_string($aka) && $aka !== '') {
                $first = $aka;
                break;
            }
        }
        if ($first === null) {
            return null;
        }
        $domain = $first;
        if (str_starts_with($domain, 'did:web:')) {
            $domain = self::decodeUriComponent(explode(':', substr($domain, strlen('did:web:')))[0]);
        }
        $domain = strtolower(self::trim($domain));
        return $domain === '' ? null : $domain;
    }

    /** domainHash = keccak256(lowercased company domain), the registry's key for a company. */
    public static function domainHashOf(string $domain): string
    {
        return Keccak::hashHex(strtolower(self::trim($domain)));
    }

    public static function companyKeyOfCalldata(string $domainHash, string $keyId): string
    {
        return self::COMPANY_KEY_OF_SELECTOR . self::bytes32($domainHash) . self::bytes32($keyId);
    }

    public static function getCompanyDidCalldata(string $domainHash): string
    {
        return self::GET_COMPANY_DID_SELECTOR . self::bytes32($domainHash);
    }

    /**
     * Decode getCompanyDid: an outer offset, then (string did, bytes32 keyId, bytes publicKey, string didDocCid, uint64
     * verifiedAt, uint64 rotatedAt, uint8 status, address controller). Only the members this verifier reads are kept,
     * the uint64 ones as decimal strings; null on a short or malformed answer.
     *
     * @return array{keyId:string, status:int, verifiedAt:string, rotatedAt:string}|null
     */
    public static function decodeCompanyDid(mixed $data): ?array
    {
        if (!is_string($data)) {
            return null;
        }
        $h = strtolower(str_starts_with($data, '0x') ? substr($data, 2) : $data);
        if (strlen($h) < 64 || strlen($h) % 64 !== 0 || preg_match('/^[0-9a-f]*$/D', $h) !== 1) {
            return null;
        }
        $words = str_split($h, 64);
        // An offset of 2^56 or more cannot point inside any answer a string can hold.
        if (preg_match('/^0{50}/', $words[0]) !== 1) {
            return null;
        }
        $outer = (int) hexdec(substr($words[0], 50));
        if ($outer % 32 !== 0 || intdiv($outer, 32) + 8 > count($words)) {
            return null;
        }
        $t = array_slice($words, intdiv($outer, 32));
        if (preg_match('/^0{48}/', $t[4]) !== 1 || preg_match('/^0{48}/', $t[5]) !== 1 || preg_match('/^0{62}/', $t[6]) !== 1) {
            return null;
        }
        return [
            'keyId' => '0x' . $t[1],
            'status' => (int) hexdec(substr($t[6], 62)),
            'verifiedAt' => UintTime::fromHex(substr($t[4], 48)),
            'rotatedAt' => UintTime::fromHex(substr($t[5], 48)),
        ];
    }

    /**
     * The verdict on a company key at the signing instant G, from its own window (the rule citizen keys follow). G is
     * chosen by the signer; the instant the signer cannot choose is the consent anchor, which bounds G from above.
     *
     * @param array<array-key,mixed>|null $record a key record (CitizenKeyChain::decodeKeyRecord)
     * @param array{anchoredAt:?int, issuerDatedOnly:bool} $clock
     * @return array{verdict:string, window:array{validFrom:string, retiredAt:string}|null, notYetEffective:bool}
     */
    public static function verdict(?array $record, ?int $g, array $clock): array
    {
        if ($g === null || abs($g) > self::MAX_SAFE_INTEGER) {
            return ['verdict' => 'unknown', 'window' => null, 'notYetEffective' => false];
        }
        $state = CitizenKeyChain::keyWindowState($record, $g);
        $anchoredAt = $clock['anchoredAt'];
        if (
            $state === 'inForce' && $anchoredAt !== null && $anchoredAt > 0 && $anchoredAt <= self::MAX_SAFE_INTEGER
            && $g > $anchoredAt + CitizenKeyChain::SKEW
        ) {
            return ['verdict' => 'signed-after-anchor', 'window' => null, 'notYetEffective' => false];
        }
        $retiredAt = UintTime::of($record['retiredAt'] ?? null);
        if ($state === 'inForce' && $clock['issuerDatedOnly'] && $retiredAt !== '0') {
            return ['verdict' => 'retired-issuer-dated', 'window' => null, 'notYetEffective' => false];
        }
        $verdict = match ($state) {
            'inForce' => 'authentic',
            'notInForce' => 'key-not-authorised',
            'pending' => 'pending',
            'voided' => 'voided',
            'recordedLate' => 'recorded-late',
            default => 'unknown',
        };
        $validFrom = UintTime::of($record['validFrom'] ?? null);
        return [
            'verdict' => $verdict,
            'window' => $state === 'inForce' && $validFrom !== null && $retiredAt !== null
                ? ['validFrom' => $validFrom, 'retiredAt' => $retiredAt]
                : null,
            'notYetEffective' => $state === 'notInForce' && $validFrom !== null && UintTime::cmp((string) $g, $validFrom) < 0,
        ];
    }

    /**
     * The note a companyKey reading writes, word for word the TS SDK's.
     *
     * @param array<array-key,mixed> $reading verdict, keyId, domain, and rotated plus currentKeyId when read
     */
    public static function note(array $reading): string
    {
        $verdict = Json::str($reading['verdict'] ?? null);
        $head = match ($verdict) {
            'authentic' => 'Company key confirmed on chain',
            'key-not-authorised', 'signed-after-anchor', 'voided' => 'companyKeyRegistry FAILED',
            default => 'companyKeyRegistry UNAVAILABLE',
        };
        $rotated = ($reading['rotated'] ?? null) === true
            ? ' The company has since rotated to the key ' . Json::str($reading['currentKeyId'] ?? null) . '.'
            : '';
        $keyId = Json::str($reading['keyId'] ?? null);
        $domain = Json::str($reading['domain'] ?? null);
        return "{$head}: the key {$keyId} " . (self::NOTES[$verdict] ?? $verdict) . " ({$domain}).{$rotated}";
    }

    private static function bytes32(string $h): string
    {
        $hex = strtolower(str_starts_with($h, '0x') ? substr($h, 2) : $h);
        if (preg_match('/^[0-9a-f]{64}$/D', $hex) !== 1) {
            throw new InvalidArgumentException('not a bytes32');
        }
        return $hex;
    }

    /** JavaScript's String.prototype.trim: whitespace and line terminators, including the Unicode ones. */
    private static function trim(string $s): string
    {
        return (string) preg_replace('/^[\s\x{FEFF}\x{00A0}]+|[\s\x{FEFF}\x{00A0}]+$/u', '', $s);
    }

    /** JavaScript's decodeURIComponent, and the input unchanged where it would throw (a bad escape, bytes not UTF-8). */
    private static function decodeUriComponent(string $s): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $s) === 1) {
            return $s;
        }
        $decoded = rawurldecode($s);
        return preg_match('//u', $decoded) === 1 ? $decoded : $s;
    }
}
