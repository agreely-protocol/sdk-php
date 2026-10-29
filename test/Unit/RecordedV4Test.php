<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\RegistryAnswers;
use Agreely\Sdk\Test\Support\VectorCase;
use Agreely\Sdk\Verify\Recorded;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * vectors/recorded-v4.json: a version 4 paper or telephone receipt signs its anchor batch; the chain is read on the
 * SIGNED refs, and only once companyKeyOf confirms the company key at the signing instant.
 */
final class RecordedV4Test extends TestCase
{
    /** @return array<string,array{0:array<string,mixed>}> */
    public static function cases(): array
    {
        $out = [];
        foreach ((array) VectorCase::read('recorded-v4.json')['cases'] as $case) {
            /** @var array<string,mixed> $case */
            $out[VectorCase::str($case['name'])] = [$case];
        }
        return $out;
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('cases')]
    public function testRecordedV4(array $c): void
    {
        $docs = VectorCase::read('receipts/v4-did-documents.json');
        $answers = $c['answers'];
        $chain = RegistryAnswers::vectorChain($answers === null ? [] : VectorCase::map($answers));
        $result = Agreely::verifyReceipt(VectorCase::read(VectorCase::str($c['file'])), [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
        ] + ($answers !== null ? ['rpcUrl' => 'https://rpc.test', 'chainId' => 84532, 'httpPost' => $chain->post(...)] : []));
        /** @var array<string,mixed> $want */
        $want = $c['expect'];
        VectorCase::expectFields($result, $want);
        if (array_key_exists('readRefs', $want)) {
            $this->assertSame($want['readRefs'], $chain->refs);
        }
        if ($answers === null) {
            $this->assertSame([], $chain->calls);
        }
    }

    /** @return array<string,mixed> */
    private static function a3(): array
    {
        /** @var array<string,mixed> $receipt */
        $receipt = VectorCase::read('receipts/a3v4.bundle.json')['receipt'];
        return $receipt;
    }

    /**
     * @param array<string,mixed> $r
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    private static function withoutConsent(array $r, array $keys): array
    {
        /** @var array{consent:array<string,mixed>} $subject */
        $subject = $r['credentialSubject'];
        foreach ($keys as $key) {
            unset($subject['consent'][$key]);
        }
        $r['credentialSubject'] = $subject;
        return $r;
    }

    public function testAVersion4WithItsAnchorIsTheIssuersShape(): void
    {
        $this->assertSame(['kind' => 'ok'], Recorded::shapeOf(self::a3(), 'company_attested'));
    }

    public function testAVersion3NeverCarriesAnAnchorAndAVersion2NeverCarriesADeclaration(): void
    {
        $v3 = self::a3();
        $v3['receiptVersion'] = '3';
        $this->assertSame('mismatch', Recorded::shapeOf($v3, 'company_attested')['kind']);
        $v3 = self::withoutConsent($v3, ['merkleRoot', 'consentRefs']);
        $this->assertSame(['kind' => 'ok'], Recorded::shapeOf($v3, 'company_attested'));
        $v2 = $v3;
        $v2['receiptVersion'] = '2';
        /** @var array{consent:array<string,mixed>} $subject */
        $subject = $v2['credentialSubject'];
        $subject['consent']['issuerDeclaration'] = new \stdClass();
        $v2['credentialSubject'] = $subject;
        $this->assertSame('mismatch', Recorded::shapeOf($v2, 'company_attested')['kind']);
    }

    public function testAReceiptWithNoReceiptVersionCarriesNoMemberALaterVersionWrites(): void
    {
        $v1 = self::a3();
        unset($v1['receiptVersion']);
        $this->assertSame('mismatch', Recorded::shapeOf($v1, 'company_attested')['kind']);
        $v1 = self::withoutConsent($v1, ['recordedAt', 'issuerDeclaration', 'merkleRoot', 'consentRefs']);
        $this->assertSame(['kind' => 'ok'], Recorded::shapeOf($v1, 'company_attested'));
    }

    public function testAVersionThisVerifierDoesNotReadIsNamedNeverGuessed(): void
    {
        $v5 = self::a3();
        $v5['receiptVersion'] = '5';
        $this->assertSame(['kind' => 'unknown-version', 'version' => '5'], Recorded::shapeOf($v5, 'company_attested'));
        $v5['receiptVersion'] = 5.0;
        // JSON.stringify(5.0) is "5".
        $this->assertSame(['kind' => 'unknown-version', 'version' => '5'], Recorded::shapeOf($v5, 'company_attested'));
    }
}
