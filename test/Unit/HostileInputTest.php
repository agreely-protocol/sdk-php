<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Verify\ReceiptVerification;
use Agreely\Sdk\Verify\ReceiptVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Hostile inputs from the re-QA of 0.6.0 (qa-ver1r2): a deep receipt, a forged did:web citizen (c6),
 * bundle shape errors, unchecked top-level receipt keys, and the Sepolia deploy block. The PHP twin of
 * the TS SDK's verifyHostile.test.ts.
 */
final class HostileInputTest extends TestCase
{
    private const MALFORMED_WORDING = '/MALFORMED: .*not evidence that it was altered/';

    /** @return array<string,mixed> */
    private static function artefact(string $name): array
    {
        /** @var array<string,mixed> $value */
        $value = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/vectors/receipts/{$name}.json"), true);
        return $value;
    }

    /** @return array<string,mixed> */
    private static function docs(): array
    {
        return self::artefact('did-documents');
    }

    /** @param array<string,mixed> $opts */
    private static function verify(mixed $input, array $opts = []): ReceiptVerification
    {
        $docs = self::docs();
        return Agreely::verifyReceipt($input, ['resolver' => static fn (string $did): ?array => is_array($docs[$did] ?? null) ? $docs[$did] : null] + $opts);
    }

    private static function nested(int $depth): mixed
    {
        $v = 'leaf';
        for ($i = 0; $i < $depth; $i++) {
            $v = ['a' => $v];
        }
        return $v;
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private static function withSubjectMember(array $receipt, string $key, mixed $value): array
    {
        /** @var array<string,mixed> $subject */
        $subject = $receipt['credentialSubject'];
        $subject[$key] = $value;
        $receipt['credentialSubject'] = $subject;
        return $receipt;
    }

    // --- N9: never throws on bad input ----------------------------------------------

    public function testAPaperReceiptNested3000DeepIsMalformed(): void
    {
        $result = self::verify(self::withSubjectMember(self::artefact('a3v3.receipt'), 'deep', self::nested(3000)));
        $this->assertSame('malformed', $result->overall);
        $this->assertMatchesRegularExpression(self::MALFORMED_WORDING, implode("\n", $result->notes));
        $this->assertStringContainsString('nests deeper than 64 levels', implode("\n", $result->notes));
    }

    public function testAReceiptNested20000DeepReturnsInsteadOfExhaustingTheStack(): void
    {
        // Deeper than this, PHP itself segfaults freeing the array, whatever the SDK does.
        $this->assertSame('malformed', self::verify(self::withSubjectMember(self::artefact('a3v3.receipt'), 'deep', self::nested(20000)))->overall);
    }

    public function testAnOfferNested5000DeepIsMalformed(): void
    {
        $bundle = self::artefact('a1v1.bundle');
        /** @var array<string,mixed> $offer */
        $offer = $bundle['signedOffer'];
        $offer['deep'] = self::nested(5000);
        $bundle['signedOffer'] = $offer;
        $this->assertSame('malformed', self::verify($bundle)->overall);
    }

    public function testAnIpfsBodyNested3000DeepNotTheCidsBytesIsNoCopyAtAllAndNeverAThrow(): void
    {
        $body = (string) json_encode(['disclosure' => self::nested(3000)], 0, 4000);
        $result = self::verify(self::artefact('cidv1.bundle'), [
            'verifyDisclosure' => true,
            'httpGet' => static fn (string $url): string => $body,
        ]);
        $this->assertSame('unavailable', $result->disclosureCopy);
        // A gateway serving bytes that are not the file says nothing about the receipt.
        $this->assertSame('verified', $result->overall);
    }

    /** @return iterable<string,array{0:mixed}> */
    public static function nonObjects(): iterable
    {
        yield 'null' => [null];
        yield 'a list' => [[1, 2]];
        yield 'a string' => ['a string'];
        yield 'a number' => [7];
    }

    #[DataProvider('nonObjects')]
    public function testANonObjectInputIsMalformed(mixed $input): void
    {
        $this->assertSame('malformed', self::verify($input)->overall);
    }

    // --- N10: the citizen half accepts only did:agreely:citizen subjects ----------------

    public function testC6IsNotVerifiedWhenTheResolverServesTheForgersDidWebDocument(): void
    {
        // did-documents.json carries the forger's own document, as a resolver that fetches any did:web would serve it.
        $result = self::verify(self::artefact('c6-web-subject.bundle'));
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('unavailable', $result->citizenAssertion);
        $this->assertNotSame('verified', $result->overall);
        $this->assertStringContainsString('is not an Agreely citizen DID', implode("\n", $result->notes));
    }

    public function testC6UnderTheDefaultResolverNeverFetchesTheSubjectsHost(): void
    {
        $docs = self::docs();
        $seen = [];
        $result = Agreely::verifyReceipt(self::artefact('c6-web-subject.bundle'), [
            'httpGet' => static function (string $url) use ($docs, &$seen): ?string {
                $seen[] = $url;
                return match ($url) {
                    'https://app.agreely.ca/c/showcase-assurance-boreale/did.json' => (string) json_encode($docs['did:web:app.agreely.ca:c:showcase-assurance-boreale']),
                    'https://evil.example/clients/jean/did.json' => (string) json_encode($docs['did:web:evil.example:clients:jean']),
                    default => null,
                };
            },
        ]);
        $this->assertSame('unavailable', $result->citizenAssertion);
        $this->assertSame('unavailable', $result->overall);
        $this->assertSame([], array_values(array_filter($seen, static fn (string $u): bool => str_contains($u, 'evil.example'))));
    }

    public function testAGenuineBundleStillVerifiesUnderTheDefaultResolverAtTheCitizenTier(): void
    {
        $docs = self::docs();
        $citizen = 'did:agreely:citizen:demo-91bf5c897c067cb69df0';
        $result = Agreely::verifyReceipt(self::artefact('a1v1.bundle'), [
            'httpGet' => static fn (string $url): ?string => match ($url) {
                'https://app.agreely.ca/c/showcase-assurance-boreale/did.json' => (string) json_encode($docs['did:web:app.agreely.ca:c:showcase-assurance-boreale']),
                'https://my.agreely.ca/did/' . rawurlencode($citizen) => (string) json_encode($docs[$citizen]),
                default => null,
            },
        ]);
        $this->assertSame('verified', $result->overall);
    }

    // --- R7, R8, R9: the bundle shape ---------------------------------------------------

    /** @return iterable<string,array{0:mixed}> */
    public static function badVersions(): iterable
    {
        yield 'a number' => [1];
        yield 'another version' => ['99'];
        yield 'no version' => [null];
    }

    #[DataProvider('badVersions')]
    public function testABundleOfAnotherVersionIsMalformed(mixed $version): void
    {
        $bundle = self::artefact('a1v1.bundle');
        if ($version === null) {
            unset($bundle['version']);
        } else {
            $bundle['version'] = $version;
        }
        $this->assertFalse(ReceiptVerifier::isVerificationBundle($bundle));
        $result = self::verify($bundle);
        $this->assertSame('malformed', $result->overall);
        $this->assertSame('bundle', $result->input);
    }

    public function testAnExtraTopLevelKeyDoesNotMakeABundleABareReceipt(): void
    {
        $bundle = self::artefact('a1v1.bundle');
        $bundle['type'] = 'AgreelyVerificationBundle';
        $bundle['anything'] = ['x' => 'y'];
        $this->assertTrue(ReceiptVerifier::isVerificationBundle($bundle));
        $result = self::verify($bundle);
        $this->assertSame('bundle', $result->input);
        $this->assertSame('verified', $result->overall);
    }

    private const ABSENT = "\0absent";

    /** @return iterable<string,array{0:string,1:mixed}> */
    public static function malformedBundles(): iterable
    {
        yield 'a receipt that is a list' => ['receipt', [['type' => ['VerifiableCredential']]]];
        yield 'a null receipt' => ['receipt', null];
        yield 'a signedOffer that is a string' => ['signedOffer', 'offer'];
        yield 'no signedOffer on a passkey receipt' => ['signedOffer', self::ABSENT];
        yield 'no merkleRoot on a passkey receipt' => ['merkleRoot', self::ABSENT];
    }

    #[DataProvider('malformedBundles')]
    public function testAMalformedBundleHasItsOwnNoteNeverWordedAsATamper(string $key, mixed $value): void
    {
        $bundle = self::artefact('a1v1.bundle');
        if ($value === self::ABSENT) {
            unset($bundle[$key]);
        } else {
            $bundle[$key] = $value;
        }
        $result = self::verify($bundle);
        $this->assertSame('malformed', $result->overall);
        $notes = implode("\n", $result->notes);
        $this->assertMatchesRegularExpression(self::MALFORMED_WORDING, $notes);
        $this->assertDoesNotMatchRegularExpression('/tamper|altered after/i', $notes);
    }

    public function testARecordedReceiptsBundleWithoutASignedOfferIsStillABundle(): void
    {
        $this->assertTrue(ReceiptVerifier::isVerificationBundle(self::artefact('a3v3.bundle')));
        $this->assertSame('verified', self::verify(self::artefact('a3v3.bundle'))->overall);
    }

    // --- R6: top-level receipt keys in a passkey bundle -------------------------------

    /** @return iterable<string,array{0:list<string>,1:string}> */
    public static function fieldsNeverWritten(): iterable
    {
        yield 'an extra top-level key' => [['extraTop'], 'carries a top-level field the issuer never writes'];
        yield 'an extra credentialSubject key' => [['credentialSubject', 'status'], 'credentialSubject carries a field the issuer never writes'];
        yield 'an extra consent key' => [['credentialSubject', 'consent', 'status'], 'consent carries a field the issuer never writes'];
    }

    /** @param list<string> $path */
    #[DataProvider('fieldsNeverWritten')]
    public function testAFieldTheIssuerNeverWritesIsMalformedNeverATamper(array $path, string $wording): void
    {
        $bundle = self::artefact('a1v1.bundle');
        /** @var array<string,mixed> $receipt */
        $receipt = $bundle['receipt'];
        $node = &$receipt;
        foreach ($path as $key) {
            if (!is_array($node)) {
                $this->fail('the path does not lead to an object');
            }
            $node = &$node[$key];
        }
        $node = 'active';
        unset($node);
        $bundle['receipt'] = $receipt;
        $result = self::verify($bundle);
        $this->assertSame('malformed', $result->overall);
        $this->assertSame(['skipped', 'skipped', 'skipped'], [$result->companySignature, $result->receiptIntegrity, $result->citizenAssertion]);
        $this->assertStringContainsString($wording, implode("\n", $result->notes));
    }

    /** @return iterable<string,array{0:string,1:mixed}> */
    public static function topLevelEdits(): iterable
    {
        yield 'a changed type' => ['type', ['VerifiableCredential', 'ConsentReceipt', 'Extra']];
        yield 'a changed @context' => ['@context', ['https://evil.example/ctx']];
        yield 'an id that is not a receipt urn' => ['id', 'https://evil.example/receipt'];
    }

    #[DataProvider('topLevelEdits')]
    public function testATopLevelReceiptEditFailsIntegrity(string $key, mixed $value): void
    {
        $bundle = self::artefact('a1v1.bundle');
        /** @var array<string,mixed> $receipt */
        $receipt = $bundle['receipt'];
        $receipt[$key] = $value;
        $bundle['receipt'] = $receipt;
        $result = self::verify($bundle);
        $this->assertSame('fail', $result->receiptIntegrity);
        $this->assertSame('failed', $result->overall);
    }

    public function testThePassNoteNoLongerClaimsEveryFieldOfTheReceiptIsSigned(): void
    {
        $notes = implode("\n", self::verify(self::artefact('a1v1.bundle'))->notes);
        $this->assertStringNotContainsString('every field of the receipt matches', $notes);
        $this->assertStringContainsString('The receipt id is not covered by the offer.', $notes);
    }
}
