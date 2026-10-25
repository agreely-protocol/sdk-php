<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Verify\ReceiptVerification;
use Agreely\Sdk\Verify\ReceiptVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Real artefacts issued by the app (qa-ver1): genuine A1 passkey bundles, recorded A3 paper and A4 phone
 * receipts, and the forgeries F1 to F11 that SDK 0.5.0 called "verified".
 */
final class BundleVerificationTest extends TestCase
{
    private const FORGED = ['f1-purpose', 'f2-granted', 'f3-cell', 'f4-graft-keepsubj', 'f5-graft-subjX', 'f6-doc', 'f7-issuer', 'f11-basis'];
    private const DEPLOY_BLOCK = 48889369;
    private const HEAD = self::DEPLOY_BLOCK + 25000;

    /** @return array<string,mixed> */
    private static function artefact(string $name): array
    {
        /** @var array<string,mixed> $value */
        $value = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/vectors/receipts/{$name}.json"), true);
        return $value;
    }

    /** @param array<string,mixed> $opts */
    private static function verify(mixed $input, array $opts = []): ReceiptVerification
    {
        $docs = self::artefact('did-documents');
        return Agreely::verifyReceipt($input, ['resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null] + $opts);
    }

    /** @return iterable<string,array{0:string}> */
    public static function forged(): iterable
    {
        foreach (self::FORGED as $name) {
            yield $name => [$name];
        }
    }

    // --- a forged artefact is never verified (N1) ---------------------------------

    #[DataProvider('forged')]
    public function testAForgedBundleFails(string $name): void
    {
        $result = self::verify(self::artefact("{$name}.bundle"));
        $this->assertSame('bundle', $result->input);
        $this->assertSame('failed', $result->overall);
    }

    #[DataProvider('forged')]
    public function testAForgedBareReceiptIsNotVerified(string $name): void
    {
        $result = self::verify(self::artefact("{$name}.receipt"));
        $this->assertSame('receipt', $result->input);
        $this->assertContains($result->overall, ['incomplete', 'failed']);
    }

    public function testAForgedPaperAndAForgedPhoneReceiptFail(): void
    {
        $this->assertSame('failed', self::verify(self::artefact('f8-paper.receipt'))->overall);
        $this->assertSame('failed', self::verify(self::artefact('f9-phone.receipt'))->overall);
    }

    public function testASelfSignedBundleFailsBecauseKeysComeFromTheResolverNeverFromTheBundle(): void
    {
        $result = self::verify(self::artefact('f10-selfsigned-paper.bundle'));
        $this->assertSame('fail', $result->companySignature);
        $this->assertSame('failed', $result->overall);
    }

    public function testAGraftedPasskeyAssertionIsRefusedInsideABundle(): void
    {
        foreach (['f4-graft-keepsubj', 'f5-graft-subjX'] as $name) {
            $this->assertSame('fail', self::verify(self::artefact("{$name}.bundle"))->citizenAssertion);
        }
    }

    public function testNoNoteClaimsACommittedChallenge(): void
    {
        foreach (['a1v1.bundle', 'a1v1.receipt', 'f1-purpose.receipt', 'f4-graft-keepsubj.receipt'] as $name) {
            $this->assertDoesNotMatchRegularExpression('/committed challenge/i', implode("\n", self::verify(self::artefact($name))->notes));
        }
    }

    // --- a genuine artefact verifies ---------------------------------------------

    public function testTheGenuinePasskeyBundlesAreVerified(): void
    {
        foreach (['a1v1', 'a1v2'] as $name) {
            $result = self::verify(self::artefact("{$name}.bundle"));
            $this->assertSame(
                ['bundle', 'pass', 'pass', 'pass', 'verified'],
                [$result->input, $result->companySignature, $result->receiptIntegrity, $result->citizenAssertion, $result->overall],
                $name,
            );
        }
    }

    public function testAGenuineBarePasskeyReceiptIsUnboundAndIncompleteAndSaysToVerifyTheBundle(): void
    {
        foreach (['a1v1', 'a1v2'] as $name) {
            $result = self::verify(self::artefact("{$name}.receipt"));
            $this->assertSame('unbound', $result->citizenAssertion);
            $this->assertSame('incomplete', $result->overall);
            $this->assertStringContainsString('Verify the verification bundle', implode("\n", $result->notes));
        }
    }

    public function testRecordedReceiptsStillVerifyAndAPhoneReceiptIsNeverTampered(): void
    {
        foreach (['a3v3.receipt', 'a3v2.receipt', 'a3v3.bundle'] as $name) {
            $this->assertSame('verified', self::verify(self::artefact($name))->overall, $name);
        }
        foreach (['a4v3.receipt', 'a4v2.receipt', 'a4v3.bundle'] as $name) {
            $result = self::verify(self::artefact($name));
            $this->assertSame('partial', $result->overall, $name);
            $this->assertSame('pass', $result->companySignature, $name);
        }
    }

    // --- a bundle is detected (N4) -------------------------------------------------

    public function testABundleIsToldFromAReceipt(): void
    {
        $this->assertTrue(ReceiptVerifier::isVerificationBundle(self::artefact('a1v1.bundle')));
        $this->assertTrue(ReceiptVerifier::isVerificationBundle(self::artefact('a3v3.bundle')));
        $this->assertFalse(ReceiptVerifier::isVerificationBundle(self::artefact('a1v1.receipt')));
        $this->assertFalse(ReceiptVerifier::isVerificationBundle(self::artefact('a3v3.receipt')));
        $this->assertFalse(ReceiptVerifier::isVerificationBundle(null));
    }

    public function testAPasskeyBundleStrippedOfItsSignedOfferIsMalformed(): void
    {
        $bundle = self::artefact('a1v1.bundle');
        unset($bundle['signedOffer']);
        $result = self::verify($bundle);
        $this->assertSame('bundle', $result->input);
        $this->assertSame('malformed', $result->overall);
    }

    // --- the document anchor is bounded and best-effort (N6) ----------------------

    /**
     * A JSON-RPC endpoint stub: `$answer(method, params)` returns the result, or null for a transport failure.
     * `$calls` records each request.
     *
     * @param callable(string, list<mixed>): array{0:string, 1?:mixed} $answer
     * @param list<array{method:string, params:list<mixed>}> $calls
     */
    private static function rpc(callable $answer, array &$calls): callable
    {
        return static function (string $url, string $body) use ($answer, &$calls): ?string {
            /** @var array{method:string, params:list<mixed>} $req */
            $req = json_decode($body, true);
            $calls[] = $req;
            $out = $answer($req['method'], $req['params']);
            return match ($out[0]) {
                'down' => null,
                'error' => (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32005, 'message' => is_string($out[1] ?? null) ? $out[1] : 'boom']]),
                default => (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $out[1] ?? null]),
            };
        };
    }

    private static function hexBlock(int $n): string
    {
        return '0x' . dechex($n);
    }

    public function testAnRpcErrorOrATransportFailureReadsUnavailableNeverFailed(): void
    {
        foreach (['error', 'down'] as $mode) {
            $calls = [];
            $result = self::verify(self::artefact('cidv1.bundle'), [
                'rpcUrl' => 'https://rpc.test',
                'httpPost' => self::rpc(static fn (): array => [$mode], $calls),
            ]);
            $this->assertSame('unavailable', $result->documentAnchor, $mode);
            $this->assertNotSame('failed', $result->overall, $mode);
        }
    }

    public function testTheLogQueryStartsAtTheDeployBlock(): void
    {
        $calls = [];
        $result = self::verify(self::artefact('cidv1.bundle'), [
            'rpcUrl' => 'https://rpc.test',
            'httpPost' => self::rpc(
                static fn (string $m): array => $m === 'eth_blockNumber' ? ['result', self::hexBlock(self::HEAD)] : ['result', [['blockNumber' => '0x1']]],
                $calls,
            ),
        ]);
        $this->assertSame('pass', $result->documentAnchor);
        $logs = array_values(array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_getLogs'));
        /** @var array{fromBlock:string} $filter */
        $filter = $logs[0]['params'][0];
        $this->assertSame(self::hexBlock(self::DEPLOY_BLOCK), $filter['fromBlock']);
    }

    /** @return callable(string, list<mixed>): array{0:string, 1?:mixed} */
    private static function rangeLimited(): callable
    {
        return static function (string $m, array $params): array {
            if ($m === 'eth_blockNumber') {
                return ['result', self::hexBlock(self::HEAD)];
            }
            if ($m === 'eth_call') {
                return ['result', null];
            }
            /** @var array{fromBlock:string, toBlock:string} $f */
            $f = $params[0];
            if (hexdec($f['toBlock']) - hexdec($f['fromBlock']) + 1 > 10000) {
                return ['error', 'range too large'];
            }
            return ['result', []];
        };
    }

    public function testARefusedFullRangePagesBackwardsAndAnEmptyWholeRangeFails(): void
    {
        $calls = [];
        $result = self::verify(self::artefact('cidv1.bundle'), [
            'rpcUrl' => 'https://rpc.test',
            'httpPost' => self::rpc(self::rangeLimited(), $calls),
        ]);
        $this->assertSame('fail', $result->documentAnchor);
        $this->assertCount(1 + 3, array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_getLogs'));
    }

    public function testARangeLargerThanTheQueryBudgetIsUnavailable(): void
    {
        $calls = [];
        $result = self::verify(self::artefact('cidv1.bundle'), [
            'rpcUrl' => 'https://rpc.test',
            'httpPost' => self::rpc(self::rangeLimited(), $calls),
            'maxLogQueries' => 2,
        ]);
        $this->assertSame('unavailable', $result->documentAnchor);
        $this->assertNotSame('failed', $result->overall);
    }

    // --- the consent anchor is read with eth_call registry(consentRef) (N6) -------

    private static function anchorWord(string $root, int $anchoredAt, int $revokedAt = 0, int $state = 1): string
    {
        $w = static fn (int $n): string => str_pad(dechex($n), 64, '0', STR_PAD_LEFT);
        return '0x' . preg_replace('/^0x/', '', $root) . $w($anchoredAt) . $w($revokedAt) . $w($state);
    }

    public function testARecordUnderTheBundleMerkleRootPassesReadWithTheRegistrySelector(): void
    {
        $bundle = self::artefact('a1v1.bundle');
        $root = is_string($bundle['merkleRoot'] ?? null) ? $bundle['merkleRoot'] : '';
        $calls = [];
        $result = self::verify($bundle, [
            'rpcUrl' => 'https://rpc.test',
            'httpPost' => self::rpc(static fn (string $m): array => $m === 'eth_call' ? ['result', self::anchorWord($root, 1760000000)] : ['result', null], $calls),
        ]);
        $this->assertSame('pass', $result->consentAnchor);
        $this->assertSame('verified', $result->overall);
        $call = array_values(array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_call'))[0];
        /** @var list<string> $refs */
        $refs = $bundle['consentRefs'];
        /** @var array{data:string} $tx */
        $tx = $call['params'][0];
        $this->assertSame('0x7ef50298' . substr($refs[0], 2), $tx['data']);
        $this->assertSame([], array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_getLogs'));
    }

    public function testARecordUnderAnotherMerkleRootFails(): void
    {
        $calls = [];
        $result = self::verify(self::artefact('a1v1.bundle'), [
            'rpcUrl' => 'https://rpc.test',
            'httpPost' => self::rpc(static fn (string $m): array => $m === 'eth_call' ? ['result', self::anchorWord('0x' . str_repeat('99', 32), 1760000000)] : ['result', null], $calls),
        ]);
        $this->assertSame('fail', $result->consentAnchor);
        $this->assertSame('failed', $result->overall);
    }

    public function testAnRpcErrorOrARecordNotAnchoredYetIsUnavailableNeverFailed(): void
    {
        $answers = [
            static fn (): array => ['down'],
            static fn (string $m): array => $m === 'eth_call' ? ['result', self::anchorWord('0x' . str_repeat('00', 32), 0, 0, 0)] : ['result', null],
        ];
        foreach ($answers as $answer) {
            $calls = [];
            $result = self::verify(self::artefact('a1v1.bundle'), ['rpcUrl' => 'https://rpc.test', 'httpPost' => self::rpc($answer, $calls)]);
            $this->assertSame('unavailable', $result->consentAnchor);
            $this->assertNotSame('failed', $result->overall);
        }
    }

    public function testTheConsentAnchorIsNeverReadForABareReceipt(): void
    {
        $calls = [];
        $result = self::verify(self::artefact('a1v1.receipt'), [
            'rpcUrl' => 'https://rpc.test',
            'httpPost' => self::rpc(static fn (): array => ['result', null], $calls),
        ]);
        $this->assertSame('skipped', $result->consentAnchor);
        $this->assertSame([], array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_call'));
    }
}
