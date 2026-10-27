<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Crypto\Canonicalizer;
use Agreely\Sdk\Crypto\Keccak;
use Agreely\Sdk\Test\Support\RegistryAnswers;
use Agreely\Sdk\Types\Wire;
use Agreely\Sdk\Verify\ReceiptVerification;
use Agreely\Sdk\Verify\ReceiptVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The offline receipt-verifier golden-vector parity gate. Loads the SHARED
 * vectors (../../vectors/vectors.json, the very file the TS suite asserts) and
 * checks that Agreely::verifyReceipt canonicalizes (JCS) BYTE-IDENTICALLY to the
 * expected ReceiptVerification. If TS and PHP crypto/verification ever drift,
 * this file fails.
 */
final class ReceiptVerificationTest extends TestCase
{
    /**
     * @return array{fixtures: array<string,mixed>, cases: list<array<string,mixed>>}
     */
    private static function rv(): array
    {
        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vectors/vectors.json'), true);
        /** @var array{fixtures: array<string,mixed>, cases: list<array<string,mixed>>} $rv */
        $rv = $data['receiptVerification'];
        return $rv;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function didDocuments(): array
    {
        /** @var array<string,array<string,mixed>> $docs */
        $docs = self::rv()['fixtures']['didDocuments'];
        return $docs;
    }

    /**
     * @return iterable<string,array{0:array<string,mixed>}>
     */
    public static function caseProvider(): iterable
    {
        foreach (self::rv()['cases'] as $case) {
            yield Wire::str($case['name']) => [$case];
        }
    }

    /**
     * @param array<string,mixed> $case
     */
    #[DataProvider('caseProvider')]
    public function testReceiptVerificationMatchesTheGoldenVectorByteForByte(array $case): void
    {
        $docs = self::didDocuments();
        $opts = [];
        if (($case['resolveDids'] ?? false) === 'none') {
            // Simulate an unresolvable DID (network/outage): the resolver always
            // returns null, so the decisive check is "unavailable", not "fail".
            $opts['resolver'] = static fn (string $did): ?array => null;
        } elseif (($case['resolveDids'] ?? false) === true) {
            $opts['resolver'] = static fn (string $did): ?array => $docs[$did] ?? null;
        }
        if (($case['ipfs'] ?? false) === true) {
            $body = (string) json_encode(self::rv()['fixtures']['ipfsBody']);
            $opts['verifyDisclosure'] = true;
            $opts['ipfsGateway'] = static fn (string $cid): string => "https://ipfs.test/{$cid}";
            $opts['httpGet'] = static fn (string $url): string => $body;
        }

        $result = Agreely::verifyReceipt($case['receipt'], $opts);

        $canon = new Canonicalizer();
        $this->assertSame(
            $canon->encode($case['expect']),
            $canon->encode($result->toArray()),
            'ReceiptVerification drifted from the golden vector (TS<->PHP parity).',
        );
    }

    /**
     * @return iterable<string,array{0:array<string,mixed>}>
     */
    public static function bundleCaseProvider(): iterable
    {
        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/vectors/vectors.json'), true);
        /** @var array{cases: list<array<string,mixed>>} $bv */
        $bv = $data['bundleVerification'];
        foreach ($bv['cases'] as $case) {
            yield Wire::str($case['name']) => [$case];
        }
    }

    /**
     * The real artefacts (genuine bundles, recorded receipts, the forgeries 0.5.0 accepted), byte-identical to TS.
     *
     * @param array<string,mixed> $case
     */
    #[DataProvider('bundleCaseProvider')]
    public function testBundleVerificationMatchesTheGoldenVectorByteForByte(array $case): void
    {
        $dir = dirname(__DIR__, 2) . '/vectors/';
        /** @var array<string,array<string,mixed>> $docs */
        $docs = json_decode((string) file_get_contents($dir . 'receipts/did-documents.json'), true);
        $opts = [
            'resolver' => ($case['resolveDids'] ?? null) === 'none'
                ? static fn (string $did): ?array => null
                : static fn (string $did): ?array => $docs[$did] ?? null,
        ];
        if (array_key_exists('ipfsBody', $case)) {
            $body = (string) json_encode($case['ipfsBody']);
            $opts['verifyDisclosure'] = true;
            $opts['ipfsGateway'] = static fn (string $cid): string => "https://ipfs.test/{$cid}";
            $opts['httpGet'] = static fn (string $url): string => $body;
        }

        if (is_array($case['chain'] ?? null)) {
            /** @var array{chainId:int, registryAddress:string, registryAnswer:string} $chain */
            $chain = $case['chain'];
            $opts['rpcUrl'] = 'https://rpc.test';
            $opts['chainId'] = $chain['chainId'];
            $opts['registryAddress'] = $chain['registryAddress'];
            $opts['httpPost'] = static function (string $url, string $body) use ($chain): string {
                /** @var array{method?:string, params?:list<array{data?:string}>} $req */
                $req = json_decode($body, true);
                $data = $req['params'][0]['data'] ?? '';
                return (string) json_encode(($req['method'] ?? '') === 'eth_call' && str_starts_with($data, '0x7ef50298')
                    ? ['jsonrpc' => '2.0', 'id' => 1, 'result' => $chain['registryAnswer']]
                    : ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'not served by this vector']]);
            };
        }

        $input = json_decode((string) file_get_contents($dir . Wire::str($case['file'])), true);
        $result = Agreely::verifyReceipt($input, $opts);

        $canon = new Canonicalizer();
        $this->assertSame(
            $canon->encode(self::numbersAsStrings($case['expect'])),
            $canon->encode(self::numbersAsStrings($result->toArray())),
            'ReceiptVerification drifted from the golden vector (TS<->PHP parity).',
        );
    }

    /** The canonicalizer refuses numbers; a chain read carries integers (unix seconds, blocks, counts), compared as their digits. */
    private static function numbersAsStrings(mixed $value): mixed
    {
        if (is_int($value)) {
            return (string) $value;
        }
        return is_array($value) ? array_map(self::numbersAsStrings(...), $value) : $value;
    }

    public function testCitizenAssertionFailsOnAChallengeMismatch(): void
    {
        $docs = self::didDocuments();
        $citizen = self::citizenReceipt();
        // Tamper the committed challenge on the WebAuthn proof.
        /** @var array<int,array<string,mixed>> $proof */
        $proof = $citizen['proof'];
        $proof[1]['challenge'] = '0x' . str_repeat('00', 32);
        $citizen['proof'] = $proof;

        $result = Agreely::verifyReceipt($citizen, [
            'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
            // Stub the IPFS fetch so the offline test never touches the network.
            'httpGet' => static fn (string $url): ?string => null,
        ]);

        $this->assertSame('fail', $result->citizenAssertion);
        $this->assertSame('failed', $result->overall);
    }

    public function testCompanySignatureIsUnavailableWhenIssuerCannotBeResolved(): void
    {
        $manual = self::rv()['cases'][0]['receipt'];
        $result = Agreely::verifyReceipt($manual, ['resolver' => static fn (): ?array => null]);
        // A DID-resolution outage is INCONCLUSIVE, never byte-identical to a forgery.
        $this->assertSame('unavailable', $result->companySignature);
        $this->assertSame('unavailable', $result->overall);
    }

    public function testAThrowingResolverIsTreatedAsUnavailable(): void
    {
        $manual = self::rv()['cases'][0]['receipt'];
        $result = Agreely::verifyReceipt($manual, [
            'resolver' => static function (): ?array {
                throw new \RuntimeException('network down');
            },
        ]);
        $this->assertSame('unavailable', $result->companySignature);
        $this->assertSame('unavailable', $result->overall);
    }

    public function testDocumentAnchorPassesOnAConsentDocumentRecordReadByRootOf(): void
    {
        $sent = [];
        $result = $this->verifyCitizenReceipt(['chainId' => 84532], RegistryAnswers::rootWord(3), $sent);

        $this->assertSame('pass', $result->documentAnchor);
        $this->assertSame(['anchoredAt' => 1790816400, 'anchoredBlock' => 53000000], $result->documentAnchorRecord);
        /** @var array{credentialSubject: array{consent: array{document: array{ipfsCid: string}}}} $receipt */
        $receipt = self::citizenReceipt();
        $cid = $receipt['credentialSubject']['consent']['document']['ipfsCid'];
        $this->assertCount(1, $sent);
        $this->assertSame(RegistryAnswers::SEPOLIA_REGISTRY, $sent[0]['to']);
        $this->assertSame(substr(Keccak::hashHex('rootOf(bytes32)'), 0, 10) . substr(Keccak::hashHex($cid), 2), $sent[0]['data']);
    }

    /** @return iterable<string, array{int, string}> */
    public static function otherLanes(): iterable
    {
        yield 'audit family' => [1, 'as an audit-family root, not as a consent document'];
        yield 'cookie' => [2, 'as a cookie consent root, not as a consent document'];
        yield 'unknown lane' => [7, 'as a root of lane 7, not as a consent document'];
    }

    #[DataProvider('otherLanes')]
    public function testDocumentAnchorFailsOnARootOfRecordInAnotherLane(int $lane, string $reason): void
    {
        $sent = [];
        $result = $this->verifyCitizenReceipt(['chainId' => 84532], RegistryAnswers::rootWord($lane), $sent);

        $this->assertSame('fail', $result->documentAnchor);
        $this->assertNull($result->documentAnchorRecord);
        $this->assertStringContainsString($reason, implode("\n", $result->notes));
    }

    public function testDocumentAnchorIsUnavailableNotFailWhileTheDocumentIsNotAnchoredYet(): void
    {
        $sent = [];
        $result = $this->verifyCitizenReceipt(['chainId' => 84532], RegistryAnswers::rootWord(0), $sent);

        $this->assertSame('unavailable', $result->documentAnchor);
        $this->assertNull($result->documentAnchorRecord);
        $this->assertStringContainsString('not anchored yet', implode("\n", $result->notes));
        $this->assertNotSame('failed', $result->overall);
    }

    public function testDocumentAnchorFailsOnATaggedDocumentRecord(): void
    {
        $sent = [];
        $result = $this->verifyCitizenReceipt(['chainId' => 84532], RegistryAnswers::rootWord(3, 1790816400, 53000000, 9), $sent);

        $this->assertSame('fail', $result->documentAnchor);
        $this->assertStringContainsString('a tagged document', implode("\n", $result->notes));
    }

    public function testOnBaseMainnetTheDefaultChainAnchorCheckIsReadAtTheMainnetRegistry(): void
    {
        $sent = [];
        $result = $this->verifyCitizenReceipt([], RegistryAnswers::rootWord(3), $sent);

        $this->assertSame('pass', $result->documentAnchor);
        $this->assertStringNotContainsString('no known registry', implode("\n", $result->notes));
        $this->assertCount(1, $sent);
        $this->assertSame(RegistryAnswers::MAINNET_REGISTRY, $sent[0]['to']);
    }

    public function testAnExplicitRegistryAddressIsReadByEthCall(): void
    {
        $sent = [];
        $explicit = '0x00000000000000000000000000000000000000aa';
        $result = $this->verifyCitizenReceipt(['registryAddress' => $explicit], RegistryAnswers::rootWord(3), $sent);

        $this->assertSame('pass', $result->documentAnchor);
        $this->assertSame($explicit, $sent[0]['to']);
    }

    /**
     * The address the verifier ACTUALLY PUTS ON THE WIRE must be EIP-55 checksummed. Derived from a real verify()
     * call, not from the test's own copy of the constant, so this cannot pass tautologically. A mis-cased address
     * still works over JSON-RPC (addresses compare case-insensitively on chain), so bad casing is invisible at
     * runtime and would only surface where a human or a checksum-validating tool reads it.
     */
    public function testTheRegistryOnTheWireIsEip55Checksummed(): void
    {
        $sent = [];
        $this->verifyCitizenReceipt(['chainId' => 84532], RegistryAnswers::rootWord(0), $sent);
        $address = Wire::str($sent[0]['to'] ?? null);

        $this->assertSame(self::eip55($address), $address, "The registry address the verifier sends ({$address}) is not EIP-55 checksummed.");
    }

    /**
     * The citizen vector receipt verified with `$opts`, against an RPC that answers every eth_call with `$answer`.
     * `$sent` collects the eth_call parameters (`to`, `data`).
     *
     * @param array<string,mixed> $opts
     * @param list<array<string,mixed>> $sent
     */
    private function verifyCitizenReceipt(array $opts, string $answer, array &$sent): ReceiptVerification
    {
        $docs = self::didDocuments();
        return Agreely::verifyReceipt(self::citizenReceipt(), [
            'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
            'httpPost' => static function (string $url, string $b) use (&$sent, $answer): string {
                /** @var array{method?: string, params?: list<array<string,mixed>>} $req */
                $req = json_decode($b, true);
                if (($req['method'] ?? null) !== 'eth_call') {
                    return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'error' => ['message' => 'no']]);
                }
                $sent[] = $req['params'][0] ?? [];
                return (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $answer]);
            },
            'rpcUrl' => 'https://rpc.test',
        ] + $opts);
    }

    private static function eip55(string $address): string
    {
        $lower = strtolower((string) preg_replace('/^0x/i', '', $address));
        $hash = (string) preg_replace('/^0x/', '', Keccak::hashHex($lower));
        $out = '0x';
        for ($i = 0; $i < strlen($lower); $i++) {
            $char = $lower[$i];
            $out .= ctype_digit($char) || hexdec($hash[$i]) < 8 ? $char : strtoupper($char);
        }

        return $out;
    }

    /**
     * THE DEFAULT DID HOSTS, read back off the URLs the verifier actually FETCHES.
     *
     * Both defaults were wrong in v0.2.0 and both were silent:
     *
     *   - the company did:web host defaulted to the apex `agreely.ca`, which is the
     *     MARKETING SITE and 404s on /c/{slug}/did.json. The document is served by the
     *     Agreely WEB tier (app.agreely.ca); the route is MODE=WEB only.
     *   - the citizen resolver base defaulted to `api.agreely.ca`, which does not route
     *     GET /did/{did} at all (that is MODE=CITIZEN, my.agreely.ca). Every citizen
     *     receipt verified with the default resolver therefore reported
     *     citizenAssertion "unavailable" and overall "unavailable": never a false
     *     "verified", but never a real verification either.
     *
     * These assert the composed URL, not a second copy of the constant, so they cannot
     * pass tautologically. If one fails, confirm the tier that actually serves the route
     * before touching it: probe `https://<host>/c/<slug>/did.json` (expect 200) and
     * `https://<host>/did/notadid` (expect 400 "invalid did", which proves the route
     * exists) rather than assuming.
     */
    public function testCompanyDidResolvesAgainstTheWebTierByDefault(): void
    {
        $fetched = [];
        (new ReceiptVerifier([
            'httpGet' => static function (string $url) use (&$fetched): ?string {
                $fetched[] = $url;

                return null;
            },
        ]))->resolveCompanyDid('acme');

        $this->assertSame(['https://app.agreely.ca/c/acme/did.json'], $fetched);
    }

    public function testCitizenDidResolvesAgainstTheCitizenTierByDefault(): void
    {
        $citizenDid = 'did:agreely:citizen:BXZMTST2EGHYNQ62Q8AJWH8Q08';
        $fetched = [];
        Agreely::verifyReceipt(self::citizenReceipt(), [
            'verifyDisclosure' => false,
            'httpGet' => static function (string $url) use (&$fetched): ?string {
                $fetched[] = $url;

                return null;
            },
        ]);

        $this->assertContains(
            'https://my.agreely.ca/did/' . rawurlencode($citizenDid),
            $fetched,
            'The default citizen resolver must call the CITIZEN tier, which is the only tier that routes /did/{did}.',
        );
        foreach ($fetched as $url) {
            $this->assertStringNotContainsString(
                'api.agreely.ca',
                $url,
                'api.agreely.ca does not route /did/{did}; resolving there always 404s.',
            );
        }
    }

    public function testCorruptedCitizenKeyFailsCleanlyWithNoOpensslWarning(): void
    {
        // Corrupt the resolved passkey to an off-curve P-256 point (y = all zeros).
        // openssl_verify would otherwise emit a noisy PHP Warning on the malformed
        // key; the SDK swallows it so the check returns 'fail' CLEANLY. failOnWarning
        // is on in phpunit.xml, and this handler makes the intent explicit: any
        // leaked warning throws and fails the test.
        $docs = self::didDocuments();
        $citizenDid = 'did:agreely:citizen:BXZMTST2EGHYNQ62Q8AJWH8Q08';
        /** @var array<string,mixed> $doc */
        $doc = $docs[$citizenDid];
        /** @var array<int,array<string,mixed>> $vms */
        $vms = $doc['verificationMethod'];
        $cose = Wire::str($vms[0]['publicKeyCose']);
        $hex = str_starts_with($cose, '0x') ? substr($cose, 2) : $cose;
        // Replace the trailing y coordinate (32 bytes) with zeros -> not on the curve.
        $corruptHex = '0x' . substr($hex, 0, -64) . str_repeat('00', 32);
        $vms[0]['publicKeyCose'] = $corruptHex;
        $doc['verificationMethod'] = $vms;
        $docs[$citizenDid] = $doc;

        $body = (string) json_encode(self::rv()['fixtures']['ipfsBody']);

        set_error_handler(static function (int $severity, string $message): bool {
            throw new \RuntimeException("unexpected PHP warning/notice leaked: {$message}");
        });
        try {
            $result = Agreely::verifyReceipt(self::citizenReceipt(), [
                'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
                'ipfsGateway' => static fn (string $cid): string => "https://ipfs.test/{$cid}",
                'httpGet' => static fn (string $url): string => $body,
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('fail', $result->citizenAssertion);
        $this->assertSame('failed', $result->overall);
    }

    /**
     * The DID STRING resolveCompanyDid builds, which is what ends up inside a receipt.
     *
     * This pair previously pinned the APEX `agreely.ca`, which is the marketing site and
     * 404s on /c/{slug}/did.json: the assertions encoded the bug rather than catching it.
     * The composed URL is asserted in testCompanyDidResolvesAgainstTheWebTierByDefault;
     * this pins the identity that URL belongs to.
     */
    public function testResolveCompanyDidBuildsTheWebTierDidString(): void
    {
        $seen = null;
        $verifier = new ReceiptVerifier([
            'resolver' => static function (string $did) use (&$seen): ?array {
                $seen = $did;

                return null;
            },
        ]);
        $verifier->resolveCompanyDid('acme');

        $this->assertSame('did:web:app.agreely.ca:c:acme', $seen);
        // Neither the apex (marketing, 404s) nor the api tier (does not serve did.json).
        $this->assertNotSame('did:web:agreely.ca:c:acme', $seen);
        $this->assertStringNotContainsString('api.agreely.ca', (string) $seen);
    }

    /**
     * companyDidHost still overrides the default, which is how a self-hosted or
     * white-labelled deployment points the resolver at its own domain.
     */
    public function testCompanyDidHostOptionOverridesTheDefault(): void
    {
        $requested = null;
        $verifier = new ReceiptVerifier([
            'companyDidHost' => 'consent.example.test',
            'httpGet' => static function (string $url) use (&$requested): ?string {
                $requested = $url;

                return null;
            },
        ]);
        $verifier->resolveCompanyDid('acme');

        $this->assertSame('https://consent.example.test/c/acme/did.json', $requested);
    }

    public function testGenuineCompanyReceiptReportsCellLabelBindingPass(): void
    {
        $docs = self::didDocuments();
        $result = Agreely::verifyReceipt(self::companyReceipt(), [
            'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
        ]);
        $this->assertSame('pass', $result->cellLabelBinding);
        $this->assertSame('verified', $result->overall);
    }

    public function testMutatingItemCategoryBreaksTheCompanySignatureAndCellLabelBinding(): void
    {
        $docs = self::didDocuments();
        $receipt = self::companyReceipt();
        /** @var array<string,mixed> $subject */
        $subject = $receipt['credentialSubject'];
        /** @var array<string,mixed> $consent */
        $consent = $subject['consent'];
        /** @var array<int,array<string,mixed>> $items */
        $items = $consent['items'];
        $items[0]['category'] = 'HACKED';
        $consent['items'] = $items;
        $subject['consent'] = $consent;
        $receipt['credentialSubject'] = $subject;

        $result = Agreely::verifyReceipt($receipt, [
            'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
        ]);
        $this->assertSame('fail', $result->companySignature);
        $this->assertSame('fail', $result->cellLabelBinding);
        $this->assertSame('failed', $result->overall);
    }

    public function testAReceiptBodyHoldingANumberIsMalformedAndDoesNotThrow(): void
    {
        $docs = self::didDocuments();
        $receipt = self::companyReceipt();
        /** @var array<string,mixed> $evidence */
        $evidence = $receipt['evidence'] ?? [];
        $evidence['attempts'] = 2;
        $receipt['evidence'] = $evidence;

        $result = Agreely::verifyReceipt($receipt, [
            'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
        ]);
        $this->assertSame('malformed', $result->overall);
        $this->assertSame('company_attested', $result->receiptType);
        $this->assertStringContainsString('the receipt body cannot be canonicalized', implode("\n", $result->notes));
    }

    public function testMutatingItemPurposeBreaksTheCompanySignatureAndCellLabelBinding(): void
    {
        $docs = self::didDocuments();
        $receipt = self::companyReceipt();
        /** @var array<string,mixed> $subject */
        $subject = $receipt['credentialSubject'];
        /** @var array<string,mixed> $consent */
        $consent = $subject['consent'];
        /** @var array<int,array<string,mixed>> $items */
        $items = $consent['items'];
        $items[0]['purpose'] = 'HACKED';
        $consent['items'] = $items;
        $subject['consent'] = $consent;
        $receipt['credentialSubject'] = $subject;

        $result = Agreely::verifyReceipt($receipt, [
            'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
        ]);
        $this->assertSame('fail', $result->companySignature);
        $this->assertSame('fail', $result->cellLabelBinding);
        $this->assertSame('failed', $result->overall);
    }

    public function testCitizenReceiptNeverReportsCellLabelBindingPassEvenWhenLabelMutated(): void
    {
        $docs = self::didDocuments();
        $body = (string) json_encode(self::rv()['fixtures']['ipfsBody']);
        $genuine = Agreely::verifyReceipt(self::citizenReceipt(), [
            'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
            'ipfsGateway' => static fn (string $cid): string => "https://ipfs.test/{$cid}",
            'httpGet' => static fn (string $url): string => $body,
        ]);
        $this->assertSame('unsupported', $genuine->cellLabelBinding);

        $receipt = self::citizenReceipt();
        /** @var array<string,mixed> $subject */
        $subject = $receipt['credentialSubject'];
        /** @var array<string,mixed> $consent */
        $consent = $subject['consent'];
        /** @var array<int,array<string,mixed>> $items */
        $items = $consent['items'];
        $items[0]['category'] = 'HACKED'; // offline: no salt/commitment/root to cross-check against
        $consent['items'] = $items;
        $subject['consent'] = $consent;
        $receipt['credentialSubject'] = $subject;

        $result = Agreely::verifyReceipt($receipt, [
            'resolver' => static fn (string $did): ?array => $docs[$did] ?? null,
            'verifyDisclosure' => false,
        ]);
        // Offline the citizen label cannot be cryptographically FAILED (by design), but it is
        // never 'pass', and the result is at most 'partial', a relying party is not misled.
        $this->assertSame('unsupported', $result->cellLabelBinding);
        $this->assertNotSame('pass', $result->cellLabelBinding);
        $this->assertNotSame('verified', $result->overall);
        $matched = false;
        foreach ($result->notes as $note) {
            if (str_contains($note, 'Do NOT trust the displayed labels')) {
                $matched = true;
            }
        }
        $this->assertTrue($matched, 'expected the explicit do-not-trust-labels note on a citizen receipt');
    }

    /** @return array<string,mixed> */
    private static function companyReceipt(): array
    {
        /** @var array<string,mixed> $receipt */
        $receipt = self::rv()['cases'][0]['receipt'];
        return $receipt;
    }

    /** @return array<string,mixed> */
    private static function citizenReceipt(): array
    {
        foreach (self::rv()['cases'] as $case) {
            if (($case['ipfs'] ?? false) === true) {
                /** @var array<string,mixed> $receipt */
                $receipt = $case['receipt'];
                return $receipt;
            }
        }
        self::fail('no citizen case in vectors');
    }

    public function testAGenuineVerbalReceiptIsCompanyDocumentedAndAtMostPartial(): void
    {
        [$receipt, $resolver] = self::verbalReceipt();
        $result = Agreely::verifyReceipt($receipt, ['resolver' => $resolver]);

        $this->assertSame('company_documented', $result->receiptType);
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('unsupported', $result->citizenAssertion);
        $this->assertSame('pass', $result->cellLabelBinding);
        $this->assertSame('partial', $result->overall, 'a telephone consent is never "verified"');
        $matched = false;
        foreach ($result->notes as $note) {
            $documented = str_contains($note, 'documented a consent given by telephone under script version script-2026.09');
            if ($documented && str_contains($note, 'there is no signed document')) {
                $matched = true;
            }
            $this->assertStringNotContainsString('hand-signed PDF', $note);
        }
        $this->assertTrue($matched, 'expected the documented-by-the-organisation note');
    }

    public function testATamperedVerbalReceiptFails(): void
    {
        [$receipt, $resolver] = self::verbalReceipt();
        /** @var array<string,mixed> $subject */
        $subject = $receipt['credentialSubject'];
        /** @var array<string,mixed> $consent */
        $consent = $subject['consent'];
        /** @var array<int,array<string,mixed>> $items */
        $items = $consent['items'];
        $items[0]['purpose'] = 'HACKED';
        $consent['items'] = $items;
        $subject['consent'] = $consent;
        $receipt['credentialSubject'] = $subject;

        $result = Agreely::verifyReceipt($receipt, ['resolver' => $resolver]);
        $this->assertSame('company_documented', $result->receiptType);
        $this->assertSame('fail', $result->companySignature);
        $this->assertSame('fail', $result->cellLabelBinding);
        $this->assertSame('failed', $result->overall);
    }

    public function testAVerbalReceiptIsRecognisedByItsAssuranceLevelAlone(): void
    {
        [$receipt, $resolver] = self::verbalReceipt();
        $receipt['type'] = ['VerifiableCredential', 'ConsentReceipt'];
        $result = Agreely::verifyReceipt($receipt, ['resolver' => $resolver]);
        $this->assertSame('company_documented', $result->receiptType);
        $this->assertSame('fail', $result->companySignature, 'the type is inside the signed body');
        $this->assertNotSame('verified', $result->overall);
    }

    public function testAVerbalReceiptWithAnUnresolvableIssuerIsUnavailable(): void
    {
        [$receipt] = self::verbalReceipt();
        $result = Agreely::verifyReceipt($receipt, ['resolver' => static fn (string $did): ?array => null]);
        $this->assertSame('unavailable', $result->companySignature);
        $this->assertSame('unavailable', $result->overall);
    }

    /**
     * A VerbalConsentReceipt shaped exactly like the one the API builds
     * (App\Models\Consent\Services\VerbalConsentReceipt), signed with a fresh key.
     *
     * @return array{0: array<string,mixed>, 1: callable(string): ?array<string,mixed>}
     */
    private static function verbalReceipt(): array
    {
        $did = 'did:web:app.agreely.ca:c:acme';
        $vm = $did . '#kms-1';
        $pair = sodium_crypto_sign_keypair();
        $body = [
            '@context' => ['https://www.w3.org/ns/credentials/v2', 'https://agreely.ca/credentials/consent/v1'],
            'type' => ['VerifiableCredential', 'ConsentReceipt', 'VerbalConsentReceipt'],
            'id' => 'urn:agreely:receipt:vc-test-1',
            'issuer' => $did,
            'assuranceLevel' => 'company_documented',
            'validFrom' => '2026-09-29T14:05:00Z',
            'validUntil' => '2027-09-30T03:59:59Z',
            'credentialSubject' => [
                'consent' => [
                    'action' => 'grant',
                    'channel' => 'telephone',
                    'items' => [['itemId' => '0x01', 'category' => 'courriel', 'purpose' => 'infolettre']],
                    'documentVersion' => 'docver-1',
                    'document' => ['code' => 'marketing', 'name' => 'Marketing', 'version' => '1.0', 'effectiveDate' => '2026-01-01'],
                    'grantedAt' => '2026-09-29T14:05:00Z',
                    'declinedItems' => [['itemId' => '0x02', 'category' => 'courriel', 'purpose' => 'sondages']],
                ],
            ],
            'evidence' => [
                'type' => 'VerbalConsentDocumentedByOrganisation',
                'scriptVersion' => 'script-2026.09',
                'documentVersion' => 'docver-1',
            ],
        ];
        $signature = sodium_crypto_sign_detached(
            (new Canonicalizer())->encode($body),
            sodium_crypto_sign_secretkey($pair),
        );
        $body['proof'] = [[
            'type' => 'DataIntegrityProof',
            'cryptosuite' => 'eddsa-jcs-2022',
            'created' => '2026-09-29T14:07:12Z',
            'verificationMethod' => $vm,
            'proofPurpose' => 'assertionMethod',
            'proofValue' => 'z' . self::base58($signature),
        ]];
        $doc = [
            'id' => $did,
            'verificationMethod' => [[
                'id' => $vm,
                'type' => 'Multikey',
                'controller' => $did,
                'publicKeyMultibase' => 'z' . self::base58("\xed\x01" . sodium_crypto_sign_publickey($pair)),
            ]],
            'assertionMethod' => [$vm],
        ];
        $resolver = static fn (string $asked): ?array => $asked === $did ? $doc : null;
        return [$body, $resolver];
    }

    private static function base58(string $bytes): string
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $digits = [0];
        foreach (str_split($bytes) as $char) {
            $carry = ord($char);
            foreach ($digits as $i => $digit) {
                $carry += $digit << 8;
                $digits[$i] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }
        $out = '';
        foreach (str_split($bytes) as $char) {
            if ($char !== "\x00") {
                break;
            }
            $out .= '1';
        }
        foreach (array_reverse($digits) as $digit) {
            $out .= $alphabet[$digit];
        }
        return $out;
    }
}
