<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

use Agreely\Sdk\Crypto\Canonicalizer;
use Agreely\Sdk\Crypto\Keccak;
use InvalidArgumentException;

/**
 * The person's key on chain, as the web verifier reads it (public/assets/verify/citizen-key-chain.js):
 *
 *   claim = {"did": <DID verbatim>, "domain": "agreely:citizen-key:v1", "keyThumbprint": keccak256(<COSE_Key bytes>)}
 *   fp    = keccak256( JCS(claim) || salt32 )
 *
 * then one keyOf(fp) read, and the rule that turns the record into a reading. Every on-chain time is a uint64 held as a
 * decimal string (UintTime): an int overflows above PHP_INT_MAX and a float rounds above 2^53. A key record is
 * array{validFrom:string, anchoredAt:string, retiredAt:string, voidedAt:string}. Vectors:
 * vectors/citizen-key-fingerprint.json (frozen) and vectors/citizen-key-amber.json.
 */
final class CitizenKeyChain
{
    public const DOMAIN = 'agreely:citizen-key:v1';
    /** How far before its anchor block a declared validFrom is still trusted, in seconds (72 h). */
    public const LAG = 259200;
    /** How far after the consent's anchor block a grantedAt may still fall, in seconds. */
    public const SKEW = 300;
    /** keccak256("keyOf(bytes32)")[0..4]. */
    public const KEY_OF_SELECTOR = '0xdf78bce2';
    /** The amber readings that withhold the person's key whatever its DID document says. */
    public const CHAIN_WITHHOLDS = ['voided', 'notLinked', 'notRecorded', 'unreadable', 'consentRecordedLate'];

    private const SALT_PATTERN = '/^0x[0-9a-f]{64}$/D';
    private const BYTES32_PATTERN = '/^0x[0-9a-fA-F]{64}$/D';
    private const KEY_RECORD_PATTERN = '/^0x[0-9a-fA-F]{256}$/D';
    private const RFC3339 = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d+)?(Z|[+-]\d{2}:\d{2})$/D';
    /** `/did` answers that do not refuse the key. */
    private const DID_NOT_REFUSING = ['confirmed', 'unknownIdentity', 'unreachable'];
    private const NOTES = [
        'chainConfirmed' => "the registry records the person's key in force at grantedAt, and the consent's anchor bounds that date",
        'notInForce' => "the registry records the person's key as NOT in force at grantedAt (not valid yet, or retired)",
        'conflict' => "the registry and the person's DID document disagree about the key",
        'didRefused' => "the person's DID document refuses the key",
        'grantedAfterConsentAnchor' => "grantedAt is later than the consent's own anchor allows, so the date is contradicted",
        'recordedLate' => "the registry recorded the key's window more than 72 hours after grantedAt",
        'voided' => "the registry records the person's key as voided",
        'consentRecordedLate' => "grantedAt falls more than 72 hours before the consent's earliest anchor",
        'consentNotAnchored' => 'the key is in force at grantedAt, but no anchor of this consent bounds that date yet',
        'didNotConsulted' => 'the key is in force at grantedAt, but its DID document standing was not read',
        'notLinked' => 'the bundle carries no citizenKeySalt, so its key cannot be looked up on chain',
        'pending' => 'the registry has no record of the key yet, and anchoring takes up to 72 hours after grantedAt',
        'unreachable' => 'the registry could not be read',
        'notRecorded' => 'the registry still has no record of the key 72 hours after grantedAt',
        'unreadable' => "the bundle's citizenKeySalt or the registry's answer cannot be read",
    ];

    /**
     * The claim object. $keyCose is the COSE_Key the assertion verified with, as hex (with or without 0x).
     *
     * @return array{did:string, domain:string, keyThumbprint:string}
     */
    public static function claim(string $did, string $keyCose): array
    {
        return ['did' => $did, 'domain' => self::DOMAIN, 'keyThumbprint' => Keccak::hashHex(self::hexBytes($keyCose))];
    }

    /** fp = keccak256( JCS(claim) || salt ), as 0x + 64 lowercase hex. Throws on a salt that is not 32 bytes of hex. */
    public static function fingerprint(string $did, string $keyCose, string $salt): string
    {
        $saltBytes = self::hexBytes($salt);
        if (strlen($saltBytes) !== 32) {
            throw new InvalidArgumentException('citizen key salt must be exactly 32 bytes');
        }
        return Keccak::hashHex((new Canonicalizer())->encode(self::claim($did, $keyCose)) . $saltBytes);
    }

    /**
     * The bundle's top-level citizenKeySalt. Not present is "absent"; anything other than "0x" + 64 LOWERCASE hex is
     * "unreadable", a null included. Never throws.
     *
     * @return array{status:'ok', salt:string}|array{status:'absent'}|array{status:'unreadable'}
     */
    public static function parseSalt(mixed $field, bool $present = true): array
    {
        if (!$present) {
            return ['status' => 'absent'];
        }
        if (!is_string($field) || preg_match(self::SALT_PATTERN, $field) !== 1) {
            return ['status' => 'unreadable'];
        }
        return ['status' => 'ok', 'salt' => $field];
    }

    /** The eth_call data for keyOf(fp). Throws on a fingerprint that is not 32 bytes of hex. */
    public static function keyOfCalldata(string $fp): string
    {
        if (preg_match(self::BYTES32_PATTERN, $fp) !== 1) {
            throw new InvalidArgumentException('the fingerprint must be 0x + 64 hex');
        }
        return self::KEY_OF_SELECTOR . strtolower(substr($fp, 2));
    }

    /**
     * Decode keyOf (or companyKeyOf): exactly four 32-byte words, each a uint64. Anything else (a wrong length, a word
     * whose high 24 bytes are not zero, a non-string, the empty answer of an address with no code) is null: unreadable.
     *
     * @return array{validFrom:string, anchoredAt:string, retiredAt:string, voidedAt:string}|null
     */
    public static function decodeKeyRecord(mixed $data): ?array
    {
        if (!is_string($data) || preg_match(self::KEY_RECORD_PATTERN, $data) !== 1) {
            return null;
        }
        $words = [];
        for ($i = 0; $i < 4; $i++) {
            $word = substr($data, 2 + $i * 64, 64);
            if (preg_match('/^0{48}/', $word) !== 1) {
                return null;
            }
            $words[] = UintTime::fromHex(substr($word, 48));
        }
        return ['validFrom' => $words[0], 'anchoredAt' => $words[1], 'retiredAt' => $words[2], 'voidedAt' => $words[3]];
    }

    /**
     * grantedAt (RFC 3339, an explicit offset required) as whole seconds since the epoch, floored, or null. A fractional
     * second never rounds up: a same-second consent must not read as later than its validFrom.
     * TRAP: a year below 100 is null, as the web verifier's Date.UTC reads it as 19xx and refuses the round trip.
     */
    public static function grantedAtSeconds(mixed $grantedAt): ?int
    {
        if (!is_string($grantedAt) || preg_match(self::RFC3339, $grantedAt, $m) !== 1) {
            return null;
        }
        [$y, $mo, $d, $h, $mi, $s] = array_map('intval', array_slice($m, 1, 6));
        if ($y < 100 || !checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return null;
        }
        $offset = 0;
        $zone = $m[8];
        if ($zone !== 'Z') {
            $oh = (int) substr($zone, 1, 2);
            $om = (int) substr($zone, 4, 2);
            if ($oh > 23 || $om > 59) {
                return null;
            }
            $offset = ($zone[0] === '+' ? 1 : -1) * ($oh * 3600 + $om * 60);
        }
        return self::daysFromCivil($y, $mo, $d) * 86400 + $h * 3600 + $mi * 60 + $s - $offset;
    }

    /**
     * What one key window says at the instant G, the rule citizen keys and company keys share: unreadable, pending (not
     * anchored yet), voided, notInForce (G before validFrom, or at or after retiredAt), recordedLate (G more than LAG
     * before the block that recorded the window) or inForce. A voided window is never in force, whatever else it says.
     *
     * @param array<array-key,mixed>|null $record
     * @return 'unreadable'|'pending'|'voided'|'notInForce'|'recordedLate'|'inForce'
     */
    public static function keyWindowState(?array $record, int|string|null $g): string
    {
        if ($record === null) {
            return 'unreadable';
        }
        $validFrom = UintTime::of($record['validFrom'] ?? null);
        $anchoredAt = UintTime::of($record['anchoredAt'] ?? null);
        $retiredAt = UintTime::of($record['retiredAt'] ?? null);
        $voidedAt = UintTime::of($record['voidedAt'] ?? null);
        $at = UintTime::of($g);
        if ($validFrom === null || $anchoredAt === null || $retiredAt === null || $voidedAt === null || $at === null) {
            return 'unreadable';
        }
        if ($anchoredAt === '0') {
            return 'pending';
        }
        if ($voidedAt !== '0') {
            return 'voided';
        }
        if (UintTime::cmp($at, $validFrom) < 0 || ($retiredAt !== '0' && UintTime::cmp($at, $retiredAt) >= 0)) {
            return 'notInForce';
        }
        // G < anchoredAt - LAG, written as an addition: no subtraction ever goes below zero.
        if (UintTime::cmp(UintTime::add($at, (string) self::LAG), $anchoredAt) < 0) {
            return 'recordedLate';
        }
        return 'inForce';
    }

    /**
     * What the chain says about the person's key at G; the first rule that applies wins: notLinked (no salt), unreadable
     * (malformed salt), unreachable (read failure), then keyWindowState, where a record never anchored reads pending
     * until $now reaches G + LAG and notRecorded from then on.
     * TRAP: $now is an explicit input so a vector can pin it; without it a record never anchored stays pending forever.
     *
     * @param array<array-key,mixed>|null $record
     */
    public static function chainState(
        mixed $saltField,
        bool $saltPresent,
        ?array $record,
        bool $rpcError,
        int|string|null $g,
        int|string|null $now = null,
    ): string {
        $salt = self::parseSalt($saltField, $saltPresent);
        if ($salt['status'] === 'absent') {
            return 'notLinked';
        }
        if ($salt['status'] === 'unreadable') {
            return 'unreadable';
        }
        if ($rpcError) {
            return 'unreachable';
        }
        $state = self::keyWindowState($record, $g);
        $t = UintTime::of($now);
        $at = UintTime::of($g);
        if ($state === 'pending' && $t !== null && $at !== null && UintTime::cmp($t, UintTime::add($at, (string) self::LAG)) >= 0) {
            return 'notRecorded';
        }
        return $state;
    }

    /**
     * The person's half: the chain state combined with the key's `/did` standing and the consent's own anchor. Any
     * refusal wins. $keyOfRegistry: the verifier's own configured registry serves keyOf and was consulted (never the
     * file); only then does a receipt with no salt read amber. $consentAnchor holds rootMatches, anchoredAt (the LATEST
     * anchor under the trusted root) and, optionally, earliestAnchoredAt.
     *
     * @param array<array-key,mixed>|null $consentAnchor
     * @return array{colour:'green'|'red'|'amber'|'neutral', reason:string}
     */
    public static function halfReading(
        string $chainState,
        string $didStanding,
        ?array $consentAnchor,
        int|string|null $g,
        bool $keyOfRegistry = false,
    ): array {
        $at = UintTime::of($g);
        $matching = $consentAnchor !== null && ($consentAnchor['rootMatches'] ?? null) === true;
        $anchoredAt = $matching ? UintTime::of($consentAnchor['anchoredAt'] ?? null) : null;
        $earliest = $matching && array_key_exists('earliestAnchoredAt', $consentAnchor)
            ? UintTime::of($consentAnchor['earliestAnchoredAt'])
            : $anchoredAt;
        $grantedAfterAnchor = $anchoredAt !== null && $at !== null
            && UintTime::cmp($at, UintTime::add($anchoredAt, (string) self::SKEW)) > 0;
        // This bounds the key's window, not where G sits in it: only the ceremony server can date a consent this early.
        $consentRecordedLate = $anchoredAt !== null && $earliest !== null && $at !== null
            && UintTime::cmp(UintTime::add($at, (string) self::LAG), $earliest) < 0;

        if ($chainState === 'notInForce') {
            return ['colour' => 'red', 'reason' => 'notInForce'];
        }
        if ($didStanding === 'refused') {
            return ['colour' => 'red', 'reason' => $chainState === 'inForce' ? 'conflict' : 'didRefused'];
        }
        if ($grantedAfterAnchor) {
            return ['colour' => 'red', 'reason' => 'grantedAfterConsentAnchor'];
        }
        if ($chainState === 'recordedLate' || $chainState === 'voided') {
            return ['colour' => 'amber', 'reason' => $chainState];
        }
        if ($consentRecordedLate) {
            return ['colour' => 'amber', 'reason' => 'consentRecordedLate'];
        }
        if ($chainState === 'inForce') {
            if ($anchoredAt === null || $at === null) {
                return ['colour' => 'amber', 'reason' => 'consentNotAnchored'];
            }
            return in_array($didStanding, self::DID_NOT_REFUSING, true)
                ? ['colour' => 'green', 'reason' => 'chainConfirmed']
                : ['colour' => 'amber', 'reason' => 'didNotConsulted'];
        }
        if ($chainState === 'notLinked') {
            return ['colour' => $keyOfRegistry ? 'amber' : 'neutral', 'reason' => 'notLinked'];
        }
        if ($chainState === 'pending' || $chainState === 'unreachable') {
            return ['colour' => 'neutral', 'reason' => $chainState];
        }
        if ($chainState === 'notRecorded') {
            return ['colour' => 'amber', 'reason' => 'notRecorded'];
        }
        return ['colour' => 'amber', 'reason' => 'unreadable'];
    }

    /** The note a citizenKey reading writes, word for word the TS SDK's. */
    public static function note(string $chainState, string $colour, string $reason, bool $withholds): string
    {
        $head = match (true) {
            $colour === 'green' => 'Citizen key confirmed on chain',
            $colour === 'red' => 'citizenKeyChain FAILED',
            $withholds => "citizenKeyChain UNAVAILABLE, and the person's key is withheld",
            $colour === 'amber' => 'citizenKeyChain UNAVAILABLE',
            default => 'citizenKeyChain adds nothing',
        };
        return "{$head} ({$chainState}): " . (self::NOTES[$reason] ?? $reason) . '.';
    }

    private static function hexBytes(string $hex): string
    {
        $digits = str_starts_with($hex, '0x') || str_starts_with($hex, '0X') ? substr($hex, 2) : $hex;
        if (strlen($digits) % 2 !== 0 || preg_match('/^[0-9a-fA-F]*$/D', $digits) !== 1) {
            throw new InvalidArgumentException('not hex');
        }
        return (string) hex2bin($digits);
    }

    /** Days from 1970-01-01 to a proleptic Gregorian date (H. Hinnant's days_from_civil). */
    private static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }
}
