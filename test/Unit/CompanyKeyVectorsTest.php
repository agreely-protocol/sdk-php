<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Test\Support\VectorCase;
use Agreely\Sdk\Verify\CitizenKeyChain;
use Agreely\Sdk\Verify\CompanyKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * vectors/company-key-windows.json: the company key's window judged at the signing instant against the consent
 * anchor's clock, as the web verifier's companyKeyVerdict does (issuer.js); and companyKeyOf and getCompanyDid as the
 * contract answers them (test/fixtures/registry-reads.json).
 */
final class CompanyKeyVectorsTest extends TestCase
{
    /**
     * @param list<array<string,mixed>> $cases
     * @return array<string,array{0:array<string,mixed>}>
     */
    private static function named(array $cases): array
    {
        $out = [];
        foreach ($cases as $case) {
            $out[VectorCase::str($case['name'])] = [$case];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function reads(): array
    {
        /** @var array<string,mixed> $reads */
        $reads = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/registry-reads.json'), true);
        return $reads;
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function windows(): array
    {
        /** @var list<array<string,mixed>> $cases */
        $cases = VectorCase::read('company-key-windows.json')['windows'];
        return self::named($cases);
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('windows')]
    public function testWindow(array $c): void
    {
        /** @var array{anchoredAt:?string, issuerDatedOnly:bool} $clock */
        $clock = $c['clock'];
        $record = $c['record'];
        $g = $c['G'];
        $pit = CompanyKey::verdict(
            is_array($record) ? $record : null,
            is_string($g) ? (int) $g : null,
            ['anchoredAt' => $clock['anchoredAt'] === null ? null : (int) $clock['anchoredAt'], 'issuerDatedOnly' => $clock['issuerDatedOnly']],
        );
        $this->assertSame($c['expect'], ['verdict' => $pit['verdict'], 'notYetEffective' => $pit['notYetEffective'], 'window' => $pit['window']]);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function receiptClocks(): array
    {
        /** @var list<array<string,mixed>> $cases */
        $cases = VectorCase::read('company-key-windows.json')['receiptClock'];
        return self::named($cases);
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('receiptClocks')]
    public function testReceiptClock(array $c): void
    {
        $anchor = $c['consentAnchor'];
        $clock = CompanyKey::receiptClockOf(
            is_array($anchor) ? ['rootMatches' => $anchor['rootMatches'], 'anchoredAt' => VectorCase::int($anchor['anchoredAt'])] : null,
        );
        $this->assertSame(
            $c['expect'],
            ['anchoredAt' => $clock['anchoredAt'] === null ? null : (string) $clock['anchoredAt'], 'issuerDatedOnly' => $clock['issuerDatedOnly']],
        );
    }

    public function testEveryVerdictReadsAsTheCheckTheTableNames(): void
    {
        /** @var array<string,string> $table */
        $table = VectorCase::read('company-key-windows.json')['statusOf'];
        $this->assertNotSame([], $table);
        foreach ($table as $verdict => $status) {
            $this->assertSame($status, CompanyKey::statusOf($verdict), $verdict);
        }
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function companyKeyOfReads(): array
    {
        /** @var array{cases:list<array<string,mixed>>} $read */
        $read = self::reads()['companyKeyOf'];
        return self::named($read['cases']);
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('companyKeyOfReads')]
    public function testCompanyKeyOfAsTheContractAnswersIt(array $c): void
    {
        /** @var array{selector:string} $read */
        $read = self::reads()['companyKeyOf'];
        $this->assertSame($read['selector'], CompanyKey::COMPANY_KEY_OF_SELECTOR);
        $calldata = VectorCase::str($c['calldata']);
        $this->assertSame($calldata, CompanyKey::companyKeyOfCalldata('0x' . substr($calldata, 10, 64), '0x' . substr($calldata, 74)));
        $decoded = CitizenKeyChain::decodeKeyRecord($c['returnData']);
        $this->assertNotNull($decoded);
        $this->assertSame($c['decoded'], array_map('intval', $decoded));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function getCompanyDidReads(): array
    {
        /** @var array{cases:list<array<string,mixed>>} $read */
        $read = self::reads()['getCompanyDid'];
        return self::named($read['cases']);
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('getCompanyDidReads')]
    public function testGetCompanyDidAsTheContractAnswersIt(array $c): void
    {
        /** @var array{selector:string} $read */
        $read = self::reads()['getCompanyDid'];
        $this->assertSame($read['selector'], CompanyKey::GET_COMPANY_DID_SELECTOR);
        $calldata = VectorCase::str($c['calldata']);
        $this->assertSame($calldata, CompanyKey::getCompanyDidCalldata('0x' . substr($calldata, 10)));
        /** @var array{keyId:string, status:int, verifiedAt:int, rotatedAt:int} $decoded */
        $decoded = $c['decoded'];
        $this->assertSame(
            ['keyId' => $decoded['keyId'], 'status' => $decoded['status'], 'verifiedAt' => (string) $decoded['verifiedAt'], 'rotatedAt' => (string) $decoded['rotatedAt']],
            CompanyKey::decodeCompanyDid($c['returnData']),
        );
    }

    public function testAShortOrMalformedGetCompanyDidAnswerIsUnreadable(): void
    {
        /** @var array{cases:list<array{returnData:string}>} $read */
        $read = self::reads()['getCompanyDid'];
        $this->assertNull(CompanyKey::decodeCompanyDid(substr($read['cases'][1]['returnData'], 0, 2 + 64 * 4)));
        $this->assertNull(CompanyKey::decodeCompanyDid('0x'));
        $this->assertNull(CompanyKey::decodeCompanyDid(null));
    }

    public function testTheDomainIsTheFirstAlsoKnownAsTheHostOfADidWebLowercasedAndHashedWithKeccak256(): void
    {
        $this->assertSame('demo-co.example', CompanyKey::domainFromDoc(['alsoKnownAs' => ['did:web:Demo-Co.Example:c:x']]));
        $this->assertSame('acme.example', CompanyKey::domainFromDoc(['alsoKnownAs' => ['Acme.Example']]));
        $this->assertNull(CompanyKey::domainFromDoc(['alsoKnownAs' => []]));
        $this->assertNull(CompanyKey::domainFromDoc([]));
        $this->assertSame('0xcdfabfbbf6dbc32d32f7225eacea5193d2503577c7969ced26bc7fcc1548cdee', CompanyKey::domainHashOf('demo-co.example'));
    }
}
