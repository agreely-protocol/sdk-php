<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\RegistryAnswers;
use Agreely\Sdk\Verify\ReceiptVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * test/fixtures/registry-reads.json is agreely-contracts vectors/registry-reads.json at 1cbdfa8: the exact eth_call
 * answers of AgreelyRegistry, asserted on the contract side by test/RegistryReadVectors.t.sol.
 */
final class RegistryReadsTest extends TestCase
{
    private const EMIT_ONLY = ['0x3532a9dff3c910152543afca49de7c66113c5312', '0x23577faffa306375028d33a559d0f95ced9424db'];

    /** @return array<string,mixed> */
    private static function vectors(): array
    {
        /** @var array<string,mixed> $v */
        $v = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/registry-reads.json'), true);
        return $v;
    }

    /** @return array<string, array{string, array{name: string, calldata: string, returnData: string, decoded: array<string,mixed>}}> */
    public static function registryCases(): array
    {
        return self::casesOf('registry');
    }

    /** @return array<string, array{string, array{name: string, calldata: string, returnData: string, decoded: array<string,mixed>}}> */
    public static function rootOfCases(): array
    {
        return self::casesOf('rootOf');
    }

    /** @return array<string, array{string, array{name: string, calldata: string, returnData: string, decoded: array<string,mixed>}}> */
    private static function casesOf(string $read): array
    {
        /** @var array{selector: string, cases: list<array{name: string, calldata: string, returnData: string, decoded: array<string,mixed>}>} $section */
        $section = self::vectors()[$read];
        $out = [];
        foreach ($section['cases'] as $case) {
            $out[$case['name']] = [$section['selector'], $case];
        }
        return $out;
    }

    private static function decode(string $method, string $returnData): mixed
    {
        return (new \ReflectionMethod(ReceiptVerifier::class, $method))->invoke(null, $returnData);
    }

    /** @param array{name: string, calldata: string, returnData: string, decoded: array<string,mixed>} $case */
    #[DataProvider('registryCases')]
    public function testARegistryAnswerDecodesExactly(string $selector, array $case): void
    {
        $this->assertSame($selector, substr($case['calldata'], 0, 10));
        $this->assertSame($case['decoded'], self::decode('decodeAnchorRecord', $case['returnData']));
    }

    /** @param array{name: string, calldata: string, returnData: string, decoded: array<string,mixed>} $case */
    #[DataProvider('rootOfCases')]
    public function testARootOfAnswerDecodesExactly(string $selector, array $case): void
    {
        $this->assertSame($selector, substr($case['calldata'], 0, 10));
        $this->assertSame($case['decoded'], self::decode('decodeRootRecord', $case['returnData']));
    }

    public function testARegistryAnswerIsReadAsAPrefixOfFiveWordsNeverFewer(): void
    {
        $active = self::casesOf('registry')['active'][1];
        $data = $active['returnData'];
        $this->assertSame($active['decoded'], self::decode('decodeAnchorRecord', $data . str_repeat('00', 32)));
        $this->assertNull(self::decode('decodeAnchorRecord', substr($data, 0, 2 + 256)));
    }

    public function testARootOfRecordThatContradictsItselfIsUnreadable(): void
    {
        $this->assertNull(self::decode('decodeRootRecord', RegistryAnswers::rootWord(3, 0, 0)));
        $this->assertNull(self::decode('decodeRootRecord', '0x' . str_repeat('00', 32) . str_pad('1', 64, '0', STR_PAD_LEFT) . str_repeat('00', 64)));
    }

    /** @return list<string> the lowercased JSON-RPC bodies sent for the IPFS-backed receipt vector */
    private static function sentTo(?int $chainId): array
    {
        /** @var array{receiptVerification: array{cases: list<array<string,mixed>>, fixtures: array<string,mixed>}} $v */
        $v = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vectors/vectors.json'), true);
        $rv = $v['receiptVerification'];
        $case = array_values(array_filter($rv['cases'], static fn (array $c): bool => ($c['ipfs'] ?? false) === true))[0];
        /** @var array<string,array<string,mixed>> $docs */
        $docs = $rv['fixtures']['didDocuments'];
        $body = (string) json_encode($rv['fixtures']['ipfsBody']);
        $sent = [];
        $opts = [
            'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
            'ipfsGateway' => static fn (string $cid): string => "https://ipfs.test/{$cid}",
            'httpGet' => static fn (string $url): string => $body,
            'rpcUrl' => 'https://rpc.test',
            'httpPost' => static function (string $url, string $b) use (&$sent): string {
                $sent[] = strtolower($b);
                return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => RegistryAnswers::rootWord(3)]);
            },
        ];
        if ($chainId !== null) {
            $opts['chainId'] = $chainId;
        }
        /** @var array<string,mixed> $receipt */
        $receipt = $case['receipt'];
        Agreely::verifyReceipt($receipt, $opts);
        return $sent;
    }

    public function testBaseSepoliaIsReadAtTheRegistryItServesByEthCall(): void
    {
        $sent = self::sentTo(84532);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('"to":"' . strtolower(RegistryAnswers::SEPOLIA_REGISTRY) . '"', $sent[0]);
    }

    /** @return array<string, array{?int}> */
    public static function chains(): array
    {
        return ['default' => [null], 'base' => [8453], 'base sepolia' => [84532]];
    }

    #[DataProvider('chains')]
    public function testNoChainEverReadsAnEmitOnlyRegistry(?int $chainId): void
    {
        $sent = self::sentTo($chainId);
        $this->assertCount($chainId === 84532 ? 1 : 0, $sent);
        foreach ($sent as $body) {
            foreach (self::EMIT_ONLY as $address) {
                $this->assertStringNotContainsString($address, $body);
            }
            $this->assertStringNotContainsString('eth_getlogs', $body);
        }
    }
}
