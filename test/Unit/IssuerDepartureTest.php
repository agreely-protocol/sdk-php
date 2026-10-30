<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Test\Support\LiveReads;
use Agreely\Sdk\Test\Support\VectorCase;
use Agreely\Sdk\Verify\IssuerLive;
use Agreely\Sdk\Verify\ReceiptVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** vectors/issuer-departure.json: an issuer that left Agreely, as the /verify page reads it. */
final class IssuerDepartureTest extends TestCase
{
    private const DEPARTURE_URL = 'https://verify.agreely.ca/c/demo-co/departure.json';

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function departureUrls(): array
    {
        $out = [];
        foreach ((array) VectorCase::read('issuer-departure.json')['departureUrl'] as $case) {
            $case = VectorCase::map($case);
            $out[VectorCase::str($case['did'])] = [$case];
        }
        return $out;
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('departureUrls')]
    public function testDepartureUrl(array $c): void
    {
        $this->assertSame($c['url'], IssuerLive::departureUrlOf($c['did']));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function phases(): array
    {
        return VectorCase::cases('issuer-departure.json', 'phase');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('phases')]
    public function testPhase(array $c): void
    {
        $record = ['sealedOn' => VectorCase::str($c['sealedOn']), 'purgedOn' => VectorCase::str($c['purgedOn'])];
        // The signing instant as the verifier parses it.
        $ms = (new \ReflectionMethod(ReceiptVerifier::class, 'parseInstant'))->invoke(null, $c['signedAt']);
        $this->assertSame($c['expect'], IssuerLive::departurePhaseOf($record, is_int($ms) ? $ms : null));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function counts(): array
    {
        return VectorCase::cases('issuer-departure.json', 'counts');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('counts')]
    public function testCounts(array $c): void
    {
        $reading = VectorCase::map($c['departure']) + ['url' => self::DEPARTURE_URL];
        $verdict = $c['companyKeyVerdict'];
        $this->assertSame($c['expect'], IssuerLive::departureCounts($reading, is_string($verdict) ? $verdict : null));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function records(): array
    {
        return VectorCase::cases('issuer-departure.json', 'record');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('records')]
    public function testRecord(array $c): void
    {
        $live = new LiveReads();
        $result = $live->verify([
            'file' => 'receipts/a3v4.bundle.json',
            'documents' => 'receipts/v4-did-documents.json',
            'issuerDocument' => ['deactivated' => true, 'deactivatedOn' => '2026-08-01'],
            'http' => $c['answer'] === null ? [] : [self::DEPARTURE_URL => $c['answer']],
            'answers' => null,
            'options' => [],
        ]);
        VectorCase::expectFields($result, VectorCase::map($c['expect']));
        $this->assertSame([self::DEPARTURE_URL], $live->asked);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function cases(): array
    {
        return VectorCase::cases('issuer-departure.json');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('cases')]
    public function testCase(array $c): void
    {
        $live = new LiveReads();
        $result = $live->verify($c);
        VectorCase::expectFields($result, VectorCase::map($c['expect']));
        $this->assertSame($c['asked'], $live->asked);
    }
}
