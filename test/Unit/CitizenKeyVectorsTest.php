<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Crypto\Canonicalizer;
use Agreely\Sdk\Test\Support\RegistryAnswers;
use Agreely\Sdk\Test\Support\VectorCase;
use Agreely\Sdk\Verify\CitizenKeyChain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * vectors/citizen-key-fingerprint.json is the web verifier's frozen file, copied verbatim; citizen-key-amber.json adds
 * the clock (`now`) and the configured registry (keyOfRegistry) it does not carry; citizen-key-chain.json reads both
 * keys through verifyReceipt, answered by selector.
 */
final class CitizenKeyVectorsTest extends TestCase
{
    /**
     * The cases of one section of a vector file, keyed by name.
     *
     * @return array<string,array{0:array<string,mixed>}>
     */
    private static function cases(string $file, string $section): array
    {
        $out = [];
        foreach ((array) (VectorCase::read($file)[$section] ?? []) as $case) {
            /** @var array<string,mixed> $case */
            $out[VectorCase::str($case['name'])] = [$case];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function fp(): array
    {
        return VectorCase::read('citizen-key-fingerprint.json');
    }

    public function testTheConstantsAreTheFrozenFilesOnes(): void
    {
        /** @var array{domain:string, LAG:string, SKEW:string, keyOfSelector:string} $constants */
        $constants = self::fp()['constants'];
        $this->assertSame($constants['domain'], CitizenKeyChain::DOMAIN);
        $this->assertSame($constants['LAG'], (string) CitizenKeyChain::LAG);
        $this->assertSame($constants['SKEW'], (string) CitizenKeyChain::SKEW);
        $this->assertSame($constants['keyOfSelector'], CitizenKeyChain::KEY_OF_SELECTOR);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function fingerprints(): array
    {
        return self::cases('citizen-key-fingerprint.json', 'fingerprint');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('fingerprints')]
    public function testFingerprint(array $c): void
    {
        $claim = CitizenKeyChain::claim(VectorCase::str($c['did']), VectorCase::str($c['keyCose']));
        $this->assertEquals($c['claim'], $claim);
        $this->assertSame($c['keyThumbprint'], $claim['keyThumbprint']);
        $this->assertSame($c['canonicalString'], (new Canonicalizer())->encode($claim));
        $this->assertSame($c['fingerprint'], CitizenKeyChain::fingerprint(VectorCase::str($c['did']), VectorCase::str($c['keyCose']), VectorCase::str($c['salt'])));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function saltParses(): array
    {
        return self::cases('citizen-key-fingerprint.json', 'saltParse');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('saltParses')]
    public function testSaltParse(array $c): void
    {
        $parsed = CitizenKeyChain::parseSalt($c['field'] ?? null, array_key_exists('field', $c));
        $this->assertSame($c['expect'], $parsed['status'] === 'ok' ? strtolower($parsed['salt']) : null);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function keyOfCalldatas(): array
    {
        return self::cases('citizen-key-fingerprint.json', 'keyOfCalldata');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('keyOfCalldatas')]
    public function testKeyOfCalldata(array $c): void
    {
        $this->assertSame($c['calldata'], CitizenKeyChain::keyOfCalldata(VectorCase::str($c['fingerprint'])));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function keyOfDecodes(): array
    {
        return self::cases('citizen-key-fingerprint.json', 'keyOfDecode');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('keyOfDecodes')]
    public function testKeyOfDecode(array $c): void
    {
        $this->assertSame($c['expect'], CitizenKeyChain::decodeKeyRecord($c['data']));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function grantedAts(): array
    {
        return self::cases('citizen-key-fingerprint.json', 'grantedAtSeconds');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('grantedAts')]
    public function testGrantedAtSeconds(array $c): void
    {
        $g = CitizenKeyChain::grantedAtSeconds($c['grantedAt']);
        $this->assertSame($c['G'], $g === null ? null : (string) $g);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function chainStates(): array
    {
        return self::cases('citizen-key-fingerprint.json', 'chainState');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('chainStates')]
    public function testChainState(array $c): void
    {
        $record = $c['record'];
        $state = CitizenKeyChain::chainState(
            $c['saltField'] ?? null,
            ($c['saltAbsent'] ?? false) !== true,
            is_array($record) ? $record : null,
            $c['rpcError'] === true,
            self::time($c['G']),
        );
        $this->assertSame($c['expect'], $state);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function halfReadings(): array
    {
        return self::cases('citizen-key-fingerprint.json', 'halfReading');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('halfReadings')]
    public function testHalfReading(array $c): void
    {
        $anchor = $c['consentAnchor'];
        $reading = CitizenKeyChain::halfReading(VectorCase::str($c['chainState']), VectorCase::str($c['didStanding']), is_array($anchor) ? $anchor : null, self::time($c['G']));
        $this->assertSame($c['expect'], $reading);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function amberCases(): array
    {
        return self::cases('citizen-key-amber.json', 'cases');
    }

    /** @param array<string,mixed> $c */
    #[DataProvider('amberCases')]
    public function testTheClockAndTheConfiguredRegistry(array $c): void
    {
        $g = VectorCase::str(VectorCase::read('citizen-key-amber.json')['G']);
        /** @var array<string,mixed> $chain */
        $chain = $c['chain'];
        /** @var array<string,mixed> $half */
        $half = $c['half'];
        /** @var array{chainState:string, colour:string, reason:string} $want */
        $want = $c['expect'];
        $record = $chain['record'];
        $chainState = CitizenKeyChain::chainState(
            $chain['saltField'] ?? null,
            ($chain['saltAbsent'] ?? false) !== true,
            is_array($record) ? $record : null,
            $chain['rpcError'] === true,
            $g,
            self::time($chain['now']),
        );
        $this->assertSame($want['chainState'], $chainState);
        $anchor = $half['consentAnchor'];
        $reading = CitizenKeyChain::halfReading($chainState, VectorCase::str($half['didStanding']), is_array($anchor) ? $anchor : null, $g, $half['keyOfRegistry'] === true);
        $this->assertSame(['colour' => $want['colour'], 'reason' => $want['reason']], $reading);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function keyOfReads(): array
    {
        /** @var array{keyOf:array{cases:list<array<string,mixed>>}} $reads */
        $reads = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/registry-reads.json'), true);
        $out = [];
        foreach ($reads['keyOf']['cases'] as $case) {
            $out[VectorCase::str($case['name'])] = [$case];
        }
        return $out;
    }

    /**
     * registry-reads.json: keyOf(bytes32) as the contract answers it.
     *
     * @param array<string,mixed> $c
     */
    #[DataProvider('keyOfReads')]
    public function testKeyOfAsTheContractAnswersIt(array $c): void
    {
        $calldata = VectorCase::str($c['calldata']);
        $this->assertSame(CitizenKeyChain::KEY_OF_SELECTOR, substr($calldata, 0, 10));
        $this->assertSame($calldata, CitizenKeyChain::keyOfCalldata('0x' . substr($calldata, 10)));
        $decoded = CitizenKeyChain::decodeKeyRecord($c['returnData']);
        $this->assertNotNull($decoded);
        $this->assertSame($c['decoded'], array_map('intval', $decoded));
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function chainCases(): array
    {
        return self::cases('citizen-key-chain.json', 'cases');
    }

    /**
     * citizen-key-chain.json: both keys read on chain through verifyReceipt.
     *
     * @param array<string,mixed> $c
     */
    #[DataProvider('chainCases')]
    public function testBothKeysReadOnChainThroughVerifyReceipt(array $c): void
    {
        $file = VectorCase::read('citizen-key-chain.json');
        $bundle = VectorCase::read(VectorCase::str($file['bundle']));
        if (array_key_exists('citizenKeySalt', $c)) {
            if ($c['citizenKeySalt'] === null) {
                unset($bundle['citizenKeySalt']);
            } else {
                $bundle['citizenKeySalt'] = $c['citizenKeySalt'];
            }
        }
        $docs = VectorCase::read('receipts/did-documents.json');
        $answers = $c['answers'];
        $chain = RegistryAnswers::vectorChain($answers === null ? [] : VectorCase::map($answers));
        $now = VectorCase::int($c['now']);
        $result = Agreely::verifyReceipt($bundle, [
            'resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null,
            'now' => static fn (): int => $now,
            // No network: the live DNS read of a getCompanyDid answer reads unreachable, which changes no verdict.
            'httpGet' => static fn (string $url): ?string => null,
        ] + ($c['rpc'] === true ? ['rpcUrl' => 'https://rpc.test', 'chainId' => 84532, 'httpPost' => $chain->post(...)] : []));
        /** @var array<string,mixed> $want */
        $want = $c['expect'];
        VectorCase::expectFields($result, $want);
        $data = array_map(static function (array $call): ?string {
            $first = $call['params'][0] ?? null;
            return is_array($first) && is_string($first['data'] ?? null) ? $first['data'] : null;
        }, $chain->calls);
        if ($c['rpc'] !== true) {
            $this->assertSame([], $chain->calls);
        }
        // A salt absent or malformed is read without asking the chain; a well-formed one asks keyOf(fp) once.
        $keyOfReads = array_values(array_filter($data, static fn (?string $d): bool => $d !== null && str_starts_with($d, CitizenKeyChain::KEY_OF_SELECTOR)));
        if ($c['rpc'] !== true || array_key_exists('citizenKeySalt', $c)) {
            $this->assertSame([], $keyOfReads);
        } elseif (array_key_exists('citizenKey', $want)) {
            $this->assertSame([$file['keyOfCalldata']], $keyOfReads);
        }
        if (array_key_exists('companyKeyRegistry', $want)) {
            $this->assertContains($file['companyKeyOfCalldata'], $data);
            $this->assertContains($file['getCompanyDidCalldata'], $data);
        }
    }

    /** A vector's uint64 instant: a decimal string, or null. */
    private static function time(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }
}
