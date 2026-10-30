<?php

declare(strict_types=1);

namespace Agreely\Sdk\Verify;

/**
 * Two live readings about a receipt's issuer, as the Agreely /verify page makes them, and no stricter or looser:
 *
 *   - its departure from Agreely: /c/{slug}/departure.json, asked only once the issuer's DID document says
 *     deactivated (public/assets/verify/did.js readIssuerDeparture, departurePhaseOf; ui.js departureShownFor).
 *     Vector: vectors/issuer-departure.json;
 *   - its domain's live DNS declaration: the TXT record at _agreely.<domain>, read over DNS-over-HTTPS and compared
 *     with the key the registry names for that domain today (issuer.js liveDnsRecheck). Vector: vectors/live-dns.json.
 *
 * Pure: ReceiptVerifier makes the two requests.
 */
final class IssuerLive
{
    /** The DNS-over-HTTPS JSON resolver the /verify page asks. It answers JSON only to `Accept: application/dns-json`. */
    public const DEFAULT_DOH_URL = 'https://cloudflare-dns.com/dns-query';

    /** The media type a DNS-over-HTTPS JSON resolver answers; Cloudflare refuses `application/json` with a 400. */
    public const DOH_ACCEPT = 'application/dns-json';

    /**
     * did:web:<host>:c:<slug> -> https://<host>/c/<slug>/departure.json, beside its did.json. Null for any other DID
     * shape: only an Agreely company DID has a departure record.
     */
    public static function departureUrlOf(mixed $did): ?string
    {
        if (!is_string($did) || !str_starts_with($did, 'did:web:')) {
            return null;
        }
        $parts = [];
        foreach (explode(':', substr($did, strlen('did:web:'))) as $part) {
            $decoded = self::uriComponentDecode($part);
            if ($decoded === null) {
                return null;
            }
            $parts[] = $decoded;
        }
        $host = array_shift($parts);
        if ($host === null || $host === '' || $parts === []) {
            return null;
        }
        if (preg_match('#^(https://[^/]+/c/[^/]+)/did\.json$#D', "https://{$host}/" . implode('/', $parts) . '/did.json', $m) !== 1) {
            return null;
        }
        return $m[1] . '/departure.json';
    }

    /**
     * A civil day that exists, on the proleptic Gregorian calendar: 2026-02-31 is no day.
     *
     * @phpstan-assert-if-true string $value
     */
    public static function isCivilDay(mixed $value): bool
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1) {
            return false;
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($mo < 1 || $mo > 12 || $d < 1) {
            return false;
        }
        $leap = ($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0;
        $days = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][$mo - 1];
        return $d <= $days;
    }

    /**
     * The departure a record establishes: a purged record naming this exact issuer DID, with two real civil days in
     * order. Null for anything else (another DID, another status, a day that is not real, days reversed, not an object).
     *
     * @return array{sealedOn:string, purgedOn:string}|null
     */
    public static function departureRecordOf(mixed $body, string $issuer): ?array
    {
        if ($body instanceof \stdClass) {
            $body = (array) $body;
        }
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return null;
        }
        $sealedOn = $body['sealedOn'] ?? null;
        $purgedOn = $body['purgedOn'] ?? null;
        if (($body['did'] ?? null) !== $issuer || ($body['status'] ?? null) !== 'purged') {
            return null;
        }
        if (!self::isCivilDay($sealedOn) || !self::isCivilDay($purgedOn) || strcmp($sealedOn, $purgedOn) > 0) {
            return null;
        }
        return ['sealedOn' => $sealedOn, 'purgedOn' => $purgedOn];
    }

    /**
     * Where a signing instant (milliseconds since the epoch, null when it does not parse) falls against a departure.
     * The record carries the company's civil days and the instant is absolute, so the instant is read on its UTC day
     * and the red bound is padded by a day: 'after' only when that day is strictly after purgedOn + 1, which a receipt
     * signed before the purge cannot reach from any time zone. 'before' when the day precedes sealedOn, 'during'
     * otherwise.
     *
     * @param array{sealedOn:string, purgedOn:string} $record
     * @return 'before'|'during'|'after'|'unknown'
     */
    public static function departurePhaseOf(array $record, ?int $signedAtMs): string
    {
        if ($signedAtMs === null) {
            return 'unknown';
        }
        $day = gmdate('Y-m-d', intdiv($signedAtMs - (($signedAtMs % 1000) + 1000) % 1000, 1000));
        $dayAfterPurge = gmdate('Y-m-d', self::utcMidnight($record['purgedOn']) + 86400);
        if (strcmp($day, $dayAfterPurge) > 0) {
            return 'after';
        }
        return strcmp($day, $record['sealedOn']) < 0 ? 'before' : 'during';
    }

    /**
     * Whether a departure speaks for the issuer. The record and the deactivated document both come from the issuer
     * DID's own host, which a forger can run: before or during the departure they count only once the registry
     * confirmed the company key. A receipt dated after the purge keeps its departure whatever the registry says: it can
     * only turn that host's own receipt red.
     *
     * @param array<string,mixed>|null $reading
     */
    public static function departureCounts(?array $reading, ?string $companyKeyVerdict): bool
    {
        if ($reading === null || ($reading['state'] ?? null) !== 'departed') {
            return false;
        }
        return ($reading['phase'] ?? null) === 'after' || $companyKeyVerdict === 'authentic';
    }

    /** The DNS-over-HTTPS query for the TXT record at _agreely.<domain>, encoded as JavaScript's encodeURIComponent. */
    public static function liveDnsQueryUrl(string $resolver, string $domain): string
    {
        return $resolver . '?name=' . self::uriComponentEncode('_agreely.' . $domain) . '&type=TXT';
    }

    /**
     * The resolver's JSON answer (json_decode() as an array or objects) against the key the registry names for the
     * domain today. No TXT answer is 'not-resolvable' (a placeholder domain, or a zone with no record); TXT answers
     * none of which declares key=<that key> are 'not-declared'. A TXT split into several strings is joined first.
     *
     * @return 'confirmed'|'not-declared'|'not-resolvable'
     */
    public static function liveDnsStatusOf(mixed $json, string $keyId): string
    {
        $answer = is_array($json) || $json instanceof \stdClass ? ((array) $json)['Answer'] ?? null : null;
        $txts = [];
        foreach (is_array($answer) && array_is_list($answer) ? $answer : [] as $a) {
            $a = $a instanceof \stdClass ? (array) $a : $a;
            if (!is_array($a)) {
                continue;
            }
            $type = $a['type'] ?? null;
            if ($type !== 16 && $type !== '16') {
                continue;
            }
            $data = self::jsString($a['data'] ?? null);
            $txts[] = (string) preg_replace('/"\s*"/', '', (string) preg_replace('/^"|"$/D', '', $data));
        }
        if ($txts === []) {
            return 'not-resolvable';
        }
        $want = strtolower((string) preg_replace('/^0x/i', '', $keyId));
        foreach ($txts as $txt) {
            if (preg_match('/key\s*=\s*([0-9a-fA-F]{64})/', $txt, $m) === 1 && strtolower($m[1]) === $want) {
                return 'confirmed';
            }
        }
        return 'not-declared';
    }

    /**
     * A departure reading for a human, in the result's notes.
     *
     * @param array{state:string, url:string, sealedOn?:string, purgedOn?:string, phase?:string} $d
     */
    public static function departureNote(array $d): string
    {
        $sealedOn = $d['sealedOn'] ?? '';
        $purgedOn = $d['purgedOn'] ?? '';
        $left = "the issuer left Agreely on {$sealedOn} and its workspace was destroyed on {$purgedOn}";
        return match ($d['phase'] ?? null) {
            'before' => "Issuer departure: {$left}. This receipt was signed before that departure: its signature remains authentic.",
            'during' => "Issuer departure: {$left}. This receipt was signed during that departure, before its workspace was "
                . 'destroyed: its signature remains authentic.',
            'after' => "issuerDeparture FAILED: this receipt is dated after its issuer left Agreely: the issuer's workspace was "
                . "destroyed on {$purgedOn}, and Agreely has not issued receipts on its behalf since.",
            default => "issuerDeparture UNAVAILABLE: {$left}, and the receipt's signing instant does not parse, so it cannot be placed "
                . 'against that departure.',
        };
    }

    /**
     * A live DNS reading for a human, in the result's notes.
     *
     * @param array{status:string, domain:string, keyId:string, resolver:string} $d
     */
    public static function liveDnsNote(array $d): string
    {
        $name = "_agreely.{$d['domain']}";
        return match ($d['status']) {
            'confirmed' => "Live DNS: {$name} still declares the key the registry names for {$d['domain']} today ({$d['keyId']}).",
            'not-declared' => "Live DNS: {$name} no longer declares the key the registry names for {$d['domain']} today ({$d['keyId']}). "
                . 'Information only: it does not change the verdict.',
            'not-resolvable' => "Live DNS not confirmed: the resolver ({$d['resolver']}) returns no TXT record at {$name}. Information only.",
            default => "Live DNS not checked: the DNS-over-HTTPS resolver ({$d['resolver']}) could not be read. This is NOT a negative.",
        };
    }

    private static function utcMidnight(string $day): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', $day));
        return (int) gmmktime(0, 0, 0, $m, $d, $y);
    }

    /** JavaScript's String(a.data || ''): what the /verify page reads a TXT record's data as. */
    private static function jsString(mixed $v): string
    {
        return match (true) {
            $v === null, $v === false, $v === '', $v === 0, $v === 0.0 => '',
            $v === true => 'true',
            is_string($v) => $v,
            is_int($v) => (string) $v,
            is_float($v) => is_finite($v) && floor($v) === $v && abs($v) < 1e21 ? (string) (int) $v : (string) $v,
            default => is_array($v) && array_is_list($v) ? implode(',', array_map(self::jsString(...), $v)) : '[object Object]',
        };
    }

    /** JavaScript's decodeURIComponent, null where it throws (a malformed escape or bytes that are not UTF-8). */
    private static function uriComponentDecode(string $s): ?string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $s) === 1) {
            return null;
        }
        $out = rawurldecode($s);
        return mb_check_encoding($out, 'UTF-8') ? $out : null;
    }

    /** JavaScript's encodeURIComponent: everything but A-Z a-z 0-9 - _ . ! ~ * ' ( ) is percent-encoded. */
    private static function uriComponentEncode(string $s): string
    {
        return strtr(rawurlencode($s), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }
}
