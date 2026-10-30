<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Test\Support\LiveReads;
use Agreely\Sdk\Test\Support\VectorCase;
use Agreely\Sdk\Verify\IssuerLive;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** vectors/live-dns.json: the company domain's live DNS declaration, as the /verify page reads it. */
final class LiveDnsTest extends TestCase
{
    public function testTheDefaultResolverIsTheOneVerifyAsks(): void
    {
        $this->assertSame(VectorCase::read('live-dns.json')['defaultResolver'], IssuerLive::DEFAULT_DOH_URL);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function queryUrls(): array
    {
        $out = [];
        foreach ((array) VectorCase::read('live-dns.json')['queryUrl'] as $case) {
            $case = VectorCase::map($case);
            $out[VectorCase::str($case['url'])] = [$case];
        }
        return $out;
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('queryUrls')]
    public function testQueryUrl(array $c): void
    {
        $this->assertSame($c['url'], IssuerLive::liveDnsQueryUrl(VectorCase::str($c['resolver']), VectorCase::str($c['domain'])));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function statuses(): array
    {
        return VectorCase::cases('live-dns.json', 'statusOf');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('statuses')]
    public function testStatusOf(array $c): void
    {
        // The answer as the verifier reads a resolver's body: decoded from its JSON text.
        $body = json_decode(json_encode($c['body'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $keyId = VectorCase::str(VectorCase::read('live-dns.json')['keyId']);
        $this->assertSame($c['expect'], IssuerLive::liveDnsStatusOf($body, $keyId));
        $this->assertSame($c['expect'], IssuerLive::liveDnsStatusOf($c['body'], $keyId));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function cases(): array
    {
        return VectorCase::cases('live-dns.json');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('cases')]
    public function testCase(array $c): void
    {
        $live = new LiveReads();
        $result = $live->verify($c + [
            'file' => 'receipts/a3v4.bundle.json',
            'documents' => 'receipts/v4-did-documents.json',
            'issuerDocument' => null,
        ]);
        VectorCase::expectFields($result, VectorCase::map($c['expect']));
        $this->assertSame($c['asked'], $live->asked);
    }
}
