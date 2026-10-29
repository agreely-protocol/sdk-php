<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\VectorCase;
use Agreely\Sdk\Verify\Ipfs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * vectors/ipfs-gateways.json: a gateway is trusted for nothing. Its bytes count only once they re-encode to the CID;
 * anything else moves to the next gateway, and only bytes that ARE the file can fail the copy. The cases run through
 * the httpGet seam, which carries no HTTP status: an HTTP error reads as no answer there (IpfsGatewayCurlTest runs the
 * real curl path).
 */
final class IpfsGatewaysTest extends TestCase
{
    private static function pinned(): string
    {
        $file = VectorCase::str(VectorCase::read('ipfs-gateways.json')['file']);
        return (string) file_get_contents(dirname(__DIR__, 2) . "/vectors/{$file}");
    }

    private static function body(string $name): string
    {
        return match ($name) {
            'pinned' => self::pinned(),
            'html' => '<!doctype html><html><body>This domain is parked.</body></html>',
            'other-json' => '{"disclosure":{}}',
            'oversize' => str_repeat(' ', Ipfs::MAX_SINGLE_BLOCK_BYTES + 1),
            default => throw new RuntimeException("no body {$name}"),
        };
    }

    public function testTheDefaultListIsTheMeasuredOneAndThePinnedFileReEncodesToItsCid(): void
    {
        $vector = VectorCase::read('ipfs-gateways.json');
        $this->assertSame($vector['defaultGateways'], Ipfs::DEFAULT_GATEWAYS);
        $this->assertSame(4096, strlen(self::pinned()));
        $this->assertTrue(Ipfs::bytesMatchCid(VectorCase::str($vector['cid']), self::pinned()));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function cases(): array
    {
        $out = [];
        foreach ((array) VectorCase::read('ipfs-gateways.json')['cases'] as $case) {
            /** @var array<string,mixed> $case */
            $out[VectorCase::str($case['name'])] = [$case];
        }
        return $out;
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('cases')]
    public function testIpfsGateways(array $c): void
    {
        $vector = VectorCase::read('ipfs-gateways.json');
        $docs = VectorCase::read('receipts/did-documents.json');
        /** @var array<string,array{status?:int, body?:string, network?:string}> $answers */
        $answers = $c['answers'];
        $tried = [];
        $options = [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'verifyDisclosure' => true,
            // A URL with no answer, or an HTTP error, is no answer: the seam carries no status.
            'httpGet' => static function (string $url) use ($answers, &$tried): ?string {
                $tried[] = $url;
                $answer = $answers[$url] ?? ['network' => 'error'];
                if (!isset($answer['status']) || $answer['status'] < 200 || $answer['status'] > 299) {
                    return null;
                }
                return isset($answer['body']) ? self::body($answer['body']) : '';
            },
        ];
        /** @var array<string,mixed> $given */
        $given = $c['options'];
        foreach (['ipfsGateways', 'ipfsGateway'] as $option) {
            if (array_key_exists($option, $given)) {
                $options[$option] = $given[$option];
            }
        }
        $result = Agreely::verifyReceipt(VectorCase::read(VectorCase::str($vector['bundle'])), $options);
        /** @var array{disclosureCopy:string, disclosureGateway?:string, tried:list<string>} $want */
        $want = $c['expect'];
        $this->assertSame($want['disclosureCopy'], $result->disclosureCopy);
        if (!array_key_exists('disclosureGateway', $want)) {
            $this->assertArrayNotHasKey('disclosureGateway', $result->toArray());
        } else {
            $this->assertSame($want['disclosureGateway'], $result->disclosureGateway);
        }
        $this->assertSame($want['tried'], $tried);
        if ($want['disclosureCopy'] === 'unavailable' && $want['tried'] !== []) {
            $note = '';
            foreach ($result->notes as $n) {
                if (str_starts_with($n, 'disclosureCopy UNAVAILABLE')) {
                    $note = $n;
                    break;
                }
            }
            foreach ($want['tried'] as $url) {
                $this->assertStringContainsString((string) parse_url($url, PHP_URL_HOST), $note);
            }
            $this->assertStringContainsString('This is NOT a hash mismatch', $note);
        }
    }

    public function testOneByteOffABodyThatIsNotTheFileOrOneOverABlockNeverMatchesACidV0(): void
    {
        $cid = VectorCase::str(VectorCase::read('ipfs-gateways.json')['cid']);
        $flipped = self::pinned();
        $flipped[0] = $flipped[0] === "\x00" ? "\x01" : "\x00";
        $this->assertFalse(Ipfs::bytesMatchCid($cid, $flipped));
        $this->assertFalse(Ipfs::bytesMatchCid($cid, self::body('other-json')));
        $this->assertFalse(Ipfs::bytesMatchCid($cid, self::body('oversize')));
    }

    public function testABase32CidV1OverSha256IsRecomputedAndAnythingElseIsNotComputable(): void
    {
        $this->assertTrue(Ipfs::cidIsComputable(VectorCase::str(VectorCase::read('ipfs-gateways.json')['cid'])));
        $this->assertFalse(Ipfs::cidIsComputable('bafkreiv2000000000000000000000000000000000000000000000000000001'));
        $this->assertFalse(Ipfs::cidIsComputable('zb2rhe5P4gXftAwvA4eXQ5HJwsER2owDyS9sKaQRRVQPn93bA'));
        $this->assertNull(Ipfs::bytesMatchCid('not-a-cid', self::pinned()));
        $raw = 'b' . self::base32("\x01\x55\x12\x20" . hash('sha256', 'raw bytes', true));
        $this->assertTrue(Ipfs::bytesMatchCid($raw, 'raw bytes'));
        $this->assertFalse(Ipfs::bytesMatchCid($raw, 'other bytes'));
    }

    public function testAPrefixGetsTheCidAppendedATemplateGetsItSubstitutedAFunctionIsAsked(): void
    {
        $this->assertSame('https://g.example/ipfs/Qm1', Ipfs::gatewayUrl('https://g.example/ipfs/', 'Qm1'));
        $this->assertSame('https://g.example/ipfs/Qm1', Ipfs::gatewayUrl('https://g.example/ipfs', 'Qm1'));
        $this->assertSame('https://g.example/x/Qm1?a=1', Ipfs::gatewayUrl('https://g.example/x/{cid}?a=1', 'Qm1'));
        $fn = static fn (string $cid): string => "https://f.example/{$cid}";
        $this->assertSame('https://f.example/Qm1', Ipfs::gatewayUrl($fn, 'Qm1'));
        $this->assertSame('https://f.example', Ipfs::gatewayName($fn, 'https://f.example/Qm1'));
        // A string is always a URL, even one that names a PHP function.
        $this->assertSame('strtoupper/Qm1', Ipfs::gatewayUrl('strtoupper', 'Qm1'));
    }

    public function testBytesThatAreTheFileDecideEvenAgainstTheSignedHashAndNoFurtherGatewayIsAsked(): void
    {
        $cid = VectorCase::str(VectorCase::read('ipfs-gateways.json')['cid']);
        $tried = [];
        $get = static function (string $url) use (&$tried): array {
            $tried[] = $url;
            return ['body' => self::pinned(), 'status' => null];
        };
        $out = Ipfs::readPinnedFile($cid, ['https://a.example/ipfs/', 'https://b.example/ipfs/'], $get, static fn (): string => 'fail', static fn (): bool => false);
        $this->assertSame(['delivered' => true, 'gateway' => 'https://a.example/ipfs/', 'verdict' => 'fail'], $out);
        $this->assertCount(1, $tried);
    }

    public function testEachFailureIsNamedAndTheNextGatewayIsAsked(): void
    {
        $cid = VectorCase::str(VectorCase::read('ipfs-gateways.json')['cid']);
        $answers = [
            'https://a.example/ipfs/' . $cid => ['body' => null, 'status' => 502],
            'https://b.example/ipfs/' . $cid => ['body' => null, 'status' => null],
            'https://c.example/ipfs/' . $cid => ['body' => self::body('oversize'), 'status' => null],
            'https://d.example/ipfs/' . $cid => ['body' => self::body('html'), 'status' => null],
        ];
        $out = Ipfs::readPinnedFile(
            $cid,
            ['https://a.example/ipfs/', 'https://b.example/ipfs/', 'https://c.example/ipfs/', 'https://d.example/ipfs/', static fn (): int => 1],
            static fn (string $url): array => $answers[$url] ?? ['body' => null, 'status' => null],
            static fn (): string => 'pass',
            static fn (): bool => true,
        );
        $this->assertSame(['delivered' => false, 'attempts' => [
            ['gateway' => 'https://a.example/ipfs/', 'failure' => 'HTTP 502'],
            ['gateway' => 'https://b.example/ipfs/', 'failure' => 'no answer (network error or timeout)'],
            ['gateway' => 'https://c.example/ipfs/', 'failure' => 'a body over 262144 bytes'],
            ['gateway' => 'https://d.example/ipfs/', 'failure' => 'bytes that do not re-encode to the CID'],
            ['gateway' => 'the gateway function', 'failure' => 'it gave no URL'],
        ]], $out);
    }

    public function testUnderACidThisVerifierCannotRecomputeOnlyBytesTheJudgeAcceptsAreBelieved(): void
    {
        $cid = 'bafkreiv2000000000000000000000000000000000000000000000000000001';
        $bodies = ['https://a.example/ipfs/' . $cid => 'mismatch', 'https://b.example/ipfs/' . $cid => 'match'];
        $get = static fn (string $url): array => ['body' => $bodies[$url] ?? null, 'status' => null];
        $judge = static fn (string $bytes): string => $bytes === 'match' ? 'pass' : 'fail';
        $out = Ipfs::readPinnedFile($cid, ['https://a.example/ipfs/', 'https://b.example/ipfs/'], $get, $judge, static fn (string $v): bool => $v === 'pass');
        $this->assertSame(['delivered' => true, 'gateway' => 'https://b.example/ipfs/', 'verdict' => 'pass'], $out);
        $none = Ipfs::readPinnedFile($cid, ['https://a.example/ipfs/'], $get, $judge, static fn (string $v): bool => $v === 'pass');
        $this->assertSame(['delivered' => false, 'attempts' => [[
            'gateway' => 'https://a.example/ipfs/',
            'failure' => 'bytes that do not match the signed hash, under a CID this verifier cannot recompute',
        ]]], $none);
    }

    /** RFC 4648 base32, lowercase, unpadded. */
    private static function base32(string $bytes): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $out = '';
        $value = 0;
        $bits = 0;
        foreach (str_split($bytes) as $byte) {
            $value = (($value << 8) | ord($byte)) & 0xffff;
            $bits += 8;
            while ($bits >= 5) {
                $out .= $alphabet[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        return $bits > 0 ? $out . $alphabet[($value << (5 - $bits)) & 31] : $out;
    }
}
