<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\RegistryAnswers;
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
    private const SEPOLIA = ['rpcUrl' => 'https://rpc.test', 'chainId' => 84532];
    // The vectors were issued on the emit-only Base Sepolia registry, named in their signed issuer delegation.
    private const EMIT_ONLY = ['0x3532a9DfF3C910152543afCa49De7c66113C5312', '0x23577fafFa306375028D33a559D0F95Ced9424DB'];

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

    /** @return array<string,mixed> */
    private static function readable(string $name): array
    {
        return RegistryAnswers::onReadableRegistry(self::artefact($name));
    }

    /** The selector of an eth_call request's data. */
    private static function selectorOf(mixed $params): string
    {
        /** @var list<array{data?:string}> $params */
        return substr($params[0]['data'] ?? '', 0, 10);
    }

    // --- the document anchor is one eth_call rootOf(keccak256(cid)) (N6) ----------

    public function testAnRpcErrorATransportFailureOrAShortAnswerReadsUnavailableNeverFailed(): void
    {
        $answers = [
            'error' => static fn (): array => ['error'],
            'down' => static fn (): array => ['down'],
            'short' => static fn (): array => ['result', '0x' . str_repeat('00', 96)],
        ];
        foreach ($answers as $mode => $answer) {
            $calls = [];
            $result = self::verify(self::readable('cidv1.bundle'), self::SEPOLIA + ['httpPost' => self::rpc($answer, $calls)]);
            $this->assertSame('unavailable', $result->documentAnchor, $mode);
            $this->assertNotSame('failed', $result->overall, $mode);
        }
    }

    public function testALaneThreeRecordPassesInOneCallWithNoLogQuery(): void
    {
        $calls = [];
        $result = self::verify(self::readable('cidv1.bundle'), self::SEPOLIA + [
            'httpPost' => self::rpc(
                static fn (string $m, array $p): array => ['result', self::selectorOf($p) === '0x6d6f398e' ? RegistryAnswers::rootWord(3) : null],
                $calls,
            ),
        ]);
        $this->assertSame('pass', $result->documentAnchor);
        $this->assertSame(['anchoredAt' => 1790816400, 'anchoredBlock' => 53000000], $result->documentAnchorRecord);
        $this->assertSame([], array_filter($calls, static fn (array $c): bool => $c['method'] !== 'eth_call'));
    }

    public function testAnAbsentDocumentRecordIsUnavailableNotFailAndLeavesAVerifiedBundleVerified(): void
    {
        $calls = [];
        $result = self::verify(self::readable('cidv1.bundle'), self::SEPOLIA + [
            'httpPost' => self::rpc(
                static fn (string $m, array $p): array => ['result', self::selectorOf($p) === '0x6d6f398e' ? RegistryAnswers::rootWord(0) : null],
                $calls,
            ),
        ]);
        $this->assertSame('unavailable', $result->documentAnchor);
        $this->assertStringContainsString('not anchored yet', implode("\n", $result->notes));
        $this->assertSame('verified', $result->overall);
    }

    // --- a bundle naming a registry the SDK does not read is refused, with no RPC ---

    /** @return iterable<string, array{string}> */
    public static function emitOnly(): iterable
    {
        foreach (self::EMIT_ONLY as $address) {
            yield $address => [$address];
        }
    }

    #[DataProvider('emitOnly')]
    public function testABundleNamingAnEmitOnlyRegistryReadsUnavailableAsAnUnknownRegistryNeverVerified(string $address): void
    {
        $bundle = self::artefact('a1v1.bundle');
        /** @var array{didDocuments: array{issuer: array{delegation: array{body: array<string,mixed>}}}} $bundle */
        $bundle['didDocuments']['issuer']['delegation']['body']['registry'] = strtolower($address);
        $calls = [];
        $result = self::verify($bundle, self::SEPOLIA + ['httpPost' => self::rpc(static fn (): array => ['result', RegistryAnswers::rootWord(3)], $calls)]);
        $this->assertSame('unavailable', $result->consentAnchor);
        $this->assertSame('unavailable', $result->overall);
        $this->assertStringContainsString(
            'unknown registry: the bundle names the registry ' . strtolower($address) . ' on chainId 84532, which is not a known registry for chainId 84532',
            implode("\n", $result->notes),
        );
        $this->assertSame([], $calls);
    }

    public function testACidBundleNamingTheEmitOnlyRegistryReadsItsDocumentAnchorUnavailableWithNoRpc(): void
    {
        $bundle = self::artefact('cidv1.bundle');
        /** @var array{didDocuments: array{issuer: array{delegation: array{body: array<string,mixed>}}}} $bundle */
        $bundle['didDocuments']['issuer']['delegation']['body']['registry'] = strtolower(self::EMIT_ONLY[0]);
        $calls = [];
        $result = self::verify($bundle, self::SEPOLIA + ['httpPost' => self::rpc(static fn (): array => ['result', RegistryAnswers::rootWord(3)], $calls)]);
        $this->assertSame('unavailable', $result->documentAnchor);
        $this->assertNotSame('verified', $result->overall);
        $this->assertSame([], $calls);
    }

    public function testTheIssuedVectorsKeepTheirSignaturesAndTheirAnchorStepRefusesTheRegistryTheyName(): void
    {
        foreach (['a1v1.bundle', 'cidv1.bundle'] as $name) {
            $calls = [];
            $result = self::verify(self::artefact($name), self::SEPOLIA + ['httpPost' => self::rpc(static fn (): array => ['result', RegistryAnswers::rootWord(3)], $calls)]);
            $this->assertSame(['pass', 'pass', 'pass'], [$result->companySignature, $result->receiptIntegrity, $result->citizenAssertion], $name);
            $this->assertSame('unavailable', $result->consentAnchor, $name);
            $this->assertSame('unavailable', $result->overall, $name);
            $this->assertNotSame('pass', $result->documentAnchor, $name);
            $this->assertSame([], $calls, $name);
        }
    }

    public function testABundleNamingTheReadRegistryOnAnotherChainIsRefused(): void
    {
        $bundle = self::readable('a1v1.bundle');
        /** @var array{didDocuments: array{issuer: array{delegation: array{body: array<string,mixed>}}}} $bundle */
        $bundle['didDocuments']['issuer']['delegation']['body']['chainId'] = '8453';
        $calls = [];
        $result = self::verify($bundle, self::SEPOLIA + ['httpPost' => self::rpc(static fn (): array => ['result', RegistryAnswers::rootWord(3)], $calls)]);
        $this->assertSame('unavailable', $result->consentAnchor);
        $this->assertNotSame('verified', $result->overall);
        $this->assertSame([], $calls);
    }

    // --- the consent anchor is read with eth_call registry(consentRef) (N6) -------

    public function testARecordUnderTheBundleMerkleRootPassesReadWithTheRegistrySelectorItsBlockExposed(): void
    {
        $bundle = self::readable('a1v1.bundle');
        $root = is_string($bundle['merkleRoot'] ?? null) ? $bundle['merkleRoot'] : '';
        $calls = [];
        $result = self::verify($bundle, self::SEPOLIA + [
            'httpPost' => self::rpc(static fn (string $m): array => ['result', $m === 'eth_call' ? RegistryAnswers::anchorWord($root, 1760000000) : null], $calls),
        ]);
        $this->assertSame('pass', $result->consentAnchor);
        $this->assertSame('verified', $result->overall);
        $call = array_values(array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_call'))[0];
        /** @var list<string> $refs */
        $refs = $bundle['consentRefs'];
        /** @var array{data:string, to:string} $tx */
        $tx = $call['params'][0];
        $this->assertSame('0x7ef50298' . substr($refs[0], 2), $tx['data']);
        $this->assertSame(RegistryAnswers::SEPOLIA_REGISTRY, $tx['to']);
        $this->assertCount(count($refs), $result->consentAnchorRecords ?? []);
        $this->assertSame(
            ['consentRef' => strtolower($refs[0]), 'state' => 'active', 'anchoredAt' => 1760000000, 'revokedAt' => 0, 'anchoredBlock' => 53000000],
            ($result->consentAnchorRecords ?? [])[0] ?? null,
        );
        $this->assertStringContainsString('anchored in block 53000000', implode("\n", $result->notes));
    }

    public function testAWithdrawnRecordIsNeverAPass(): void
    {
        $bundle = self::readable('a1v1.bundle');
        $root = is_string($bundle['merkleRoot'] ?? null) ? $bundle['merkleRoot'] : '';
        $calls = [];
        $result = self::verify($bundle, self::SEPOLIA + [
            'httpPost' => self::rpc(static fn (string $m): array => ['result', $m === 'eth_call' ? RegistryAnswers::anchorWord($root, 1760000000, 1760000600, 2) : null], $calls),
        ]);
        $this->assertSame('withdrawn', $result->consentAnchor);
        $first = ($result->consentAnchorRecords ?? [])[0] ?? [];
        $this->assertSame('withdrawn', $first['state'] ?? null);
        $this->assertSame(1760000600, $first['revokedAt'] ?? null);
        $this->assertStringContainsString('WITHDRAWN', implode("\n", $result->notes));
        $this->assertStringContainsString('NOT a forgery', implode("\n", $result->notes));
    }

    public function testAnAuthenticWithdrawnConsentIsOverallWithdrawnOnlyWhenEverythingElseVerified(): void
    {
        $bundle = self::readable('a1v1.bundle');
        $root = is_string($bundle['merkleRoot'] ?? null) ? $bundle['merkleRoot'] : '';
        $calls = [];
        $result = self::verify($bundle, self::SEPOLIA + [
            'httpPost' => self::rpc(static fn (string $m): array => ['result', $m === 'eth_call' ? RegistryAnswers::anchorWord($root, 1760000000, 1760000600, 2) : null], $calls),
        ]);
        $this->assertSame(['pass', 'pass', 'pass'], [$result->companySignature, $result->receiptIntegrity, $result->citizenAssertion]);
        $this->assertSame('withdrawn', $result->overall);
    }

    public function testAWithdrawnConsentBesideAFailedDocumentAnchorIsFailed(): void
    {
        $bundle = self::readable('cidv1.bundle');
        $root = is_string($bundle['merkleRoot'] ?? null) ? $bundle['merkleRoot'] : '';
        $calls = [];
        $result = self::verify($bundle, self::SEPOLIA + [
            'httpPost' => self::rpc(
                static fn (string $m, array $p): array => ['result', $m !== 'eth_call' ? null
                    : (self::selectorOf($p) === '0x6d6f398e' ? RegistryAnswers::rootWord(1) : RegistryAnswers::anchorWord($root, 1760000000, 1760000600, 2))],
                $calls,
            ),
        ]);
        $this->assertSame('fail', $result->documentAnchor);
        $this->assertSame('withdrawn', $result->consentAnchor);
        $this->assertSame('failed', $result->overall);
    }

    public function testAWithdrawnConsentBesideAnUnavailableDocumentAnchorStaysWithdrawn(): void
    {
        $bundle = self::readable('cidv1.bundle');
        $root = is_string($bundle['merkleRoot'] ?? null) ? $bundle['merkleRoot'] : '';
        $calls = [];
        $result = self::verify($bundle, self::SEPOLIA + [
            'httpPost' => self::rpc(
                static fn (string $m, array $p): array => match (true) {
                    $m !== 'eth_call' => ['result', null],
                    self::selectorOf($p) === '0x6d6f398e' => ['down'],
                    default => ['result', RegistryAnswers::anchorWord($root, 1760000000, 1760000600, 2)],
                },
                $calls,
            ),
        ]);
        $this->assertSame('unavailable', $result->documentAnchor);
        $this->assertSame('withdrawn', $result->consentAnchor);
        $this->assertSame('withdrawn', $result->overall);
    }

    public function testOnBaseMainnetTheConsentAnchorIsReadAtTheMainnetRegistryByEthCall(): void
    {
        $bundle = RegistryAnswers::onMainnetRegistry(self::artefact('a1v1.bundle'));
        $root = is_string($bundle['merkleRoot'] ?? null) ? $bundle['merkleRoot'] : '';
        $calls = [];
        $result = self::verify($bundle, ['rpcUrl' => 'https://rpc.test', 'chainId' => 8453, 'httpPost' => self::rpc(static fn (string $m): array => ['result', $m === 'eth_call' ? RegistryAnswers::anchorWord($root, 1760000000) : null], $calls)]);
        $this->assertSame('pass', $result->consentAnchor);
        $this->assertStringNotContainsString('no known registry', implode("\n", $result->notes));
        $call = array_values(array_filter($calls, static fn (array $c): bool => $c['method'] === 'eth_call'))[0];
        /** @var array{to:string} $tx */
        $tx = $call['params'][0];
        $this->assertSame(RegistryAnswers::MAINNET_REGISTRY, $tx['to']);
    }

    public function testAFourWordAnswerIsUnreadableNeverARecord(): void
    {
        $bundle = self::readable('a1v1.bundle');
        $root = is_string($bundle['merkleRoot'] ?? null) ? $bundle['merkleRoot'] : '';
        $calls = [];
        $result = self::verify($bundle, self::SEPOLIA + [
            'httpPost' => self::rpc(static fn (string $m): array => ['result', $m === 'eth_call' ? substr(RegistryAnswers::anchorWord($root, 1760000000), 0, 2 + 256) : null], $calls),
        ]);
        $this->assertSame('unavailable', $result->consentAnchor);
    }

    public function testARecordUnderAnotherMerkleRootFails(): void
    {
        $calls = [];
        $result = self::verify(self::readable('a1v1.bundle'), self::SEPOLIA + [
            'httpPost' => self::rpc(static fn (string $m): array => ['result', $m === 'eth_call' ? RegistryAnswers::anchorWord('0x' . str_repeat('99', 32), 1760000000) : null], $calls),
        ]);
        $this->assertSame('fail', $result->consentAnchor);
        $this->assertSame('failed', $result->overall);
    }

    public function testAnRpcErrorOrARecordNotAnchoredYetIsUnavailableNeverFailed(): void
    {
        $answers = [
            static fn (): array => ['down'],
            static fn (string $m): array => ['result', $m === 'eth_call' ? RegistryAnswers::anchorWord('0x' . str_repeat('00', 32), 0, 0, 0) : null],
        ];
        foreach ($answers as $answer) {
            $calls = [];
            $result = self::verify(self::readable('a1v1.bundle'), self::SEPOLIA + ['httpPost' => self::rpc($answer, $calls)]);
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
