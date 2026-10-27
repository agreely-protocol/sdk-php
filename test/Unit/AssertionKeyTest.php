<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Types\Wire;
use Agreely\Sdk\Verify\ReceiptVerification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The key that checks proof[0] is the method the proof names, controlled by the issuer and listed under the issuer
 * document's assertionMethod. The served document also lists #root-1 (capabilityDelegation) and #recovery-1
 * (capabilityInvocation, a key the company holds): neither may check a receipt. A fragment naming no method falls back
 * to the document's SOLE assertionMethod key, never the first method.
 *
 * f12-recovery-key.json: the a3v3 paper receipt (genuine), the same receipt backdated with another purpose and signed
 * by a #recovery-1 key whose private half was discarded, and the document Agreely publishes for a company with a root
 * and a recovery key. The same bytes as the TS SDK's vector.
 */
final class AssertionKeyTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function vector(string $name): array
    {
        /** @var array<string,mixed> $v */
        $v = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/vectors/receipts/{$name}.json"), true);
        return $v;
    }

    /** @return array{issuerDocument: array<string,mixed>, recoverySigned: array<string,mixed>, genuine: array<string,mixed>} */
    private static function f12(): array
    {
        /** @var array{issuerDocument: array<string,mixed>, recoverySigned: array<string,mixed>, genuine: array<string,mixed>} $f */
        $f = self::vector('f12-recovery-key');
        return $f;
    }

    private static function issuer(): string
    {
        return Wire::str(self::f12()['genuine']['issuer']);
    }

    /**
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private static function labelled(array $receipt, string $fragment): array
    {
        /** @var list<array<string,mixed>> $proof */
        $proof = $receipt['proof'];
        $proof[0]['verificationMethod'] = self::issuer() . $fragment;
        $receipt['proof'] = $proof;
        return $receipt;
    }

    /** @param array<string,mixed>|null $doc */
    private static function verify(mixed $input, ?array $doc = null): ReceiptVerification
    {
        $doc ??= self::f12()['issuerDocument'];
        $issuer = self::issuer();
        return Agreely::verifyReceipt($input, ['resolver' => static fn (string $did): ?array => $did === $issuer ? $doc : null]);
    }

    private static function companyNote(ReceiptVerification $result): string
    {
        foreach ($result->notes as $note) {
            if (str_starts_with($note, 'Company signature')) {
                return $note;
            }
        }
        return '';
    }

    public function testTheGenuineReceiptVerifiesAndTheNoteNamesKms1(): void
    {
        $result = self::verify(self::f12()['genuine']);
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('verified', $result->overall);
        $this->assertStringContainsString(self::issuer() . '#kms-1', self::companyNote($result));
    }

    public function testAReceiptSignedWithTheRecoveryKeyFails(): void
    {
        $result = self::verify(self::f12()['recoverySigned']);
        $this->assertSame('fail', $result->companySignature);
        $this->assertSame('failed', $result->overall);
    }

    /** @return iterable<string,array{0:string}> */
    public static function nonAssertionFragments(): iterable
    {
        yield 'recovery-1' => ['#recovery-1'];
        yield 'root-1' => ['#root-1'];
    }

    #[DataProvider('nonAssertionFragments')]
    public function testAKmsSignatureLabelledWithANonAssertionMethodFails(string $fragment): void
    {
        $result = self::verify(self::labelled(self::f12()['genuine'], $fragment));
        $this->assertSame('fail', $result->companySignature);
        $this->assertSame('failed', $result->overall);
    }

    public function testAFragmentNamingNoMethodFallsBackToTheSoleAssertionKeyAndNamesIt(): void
    {
        $result = self::verify(self::labelled(self::f12()['genuine'], '#not-a-key'));
        $this->assertSame('pass', $result->companySignature);
        $this->assertStringContainsString(self::issuer() . '#kms-1', self::companyNote($result));
        $this->assertStringNotContainsString('#not-a-key', implode("\n", $result->notes));
    }

    public function testTheFallbackIsNeverTheFirstMethod(): void
    {
        $doc = self::f12()['issuerDocument'];
        /** @var list<mixed> $methods */
        $methods = $doc['verificationMethod'];
        $doc['verificationMethod'] = array_reverse($methods);
        $result = self::verify(self::labelled(self::f12()['recoverySigned'], '#not-a-key'), $doc);
        $this->assertSame('fail', $result->companySignature);
        $this->assertSame('failed', $result->overall);
    }

    public function testTheFallbackFindsTheSoleAssertionKeyWhenItIsNotTheFirstMethod(): void
    {
        $doc = self::f12()['issuerDocument'];
        /** @var list<mixed> $methods */
        $methods = $doc['verificationMethod'];
        $doc['verificationMethod'] = array_reverse($methods);
        $result = self::verify(self::labelled(self::f12()['genuine'], '#not-a-key'), $doc);
        $this->assertSame('pass', $result->companySignature);
        $this->assertStringContainsString(self::issuer() . '#kms-1', self::companyNote($result));
    }

    public function testAFragmentNamingNoMethodFailsWhenTheDocumentListsTwoAssertionKeys(): void
    {
        $doc = self::f12()['issuerDocument'];
        /** @var list<mixed> $assertion */
        $assertion = $doc['assertionMethod'];
        $doc['assertionMethod'] = [...$assertion, '#recovery-1'];
        $result = self::verify(self::labelled(self::f12()['genuine'], '#not-a-key'), $doc);
        $this->assertSame('fail', $result->companySignature);
    }

    public function testAnAssertionKeyControlledByAnotherDidNeverChecksTheReceipt(): void
    {
        $doc = self::f12()['issuerDocument'];
        /** @var list<array<string,mixed>> $methods */
        $methods = $doc['verificationMethod'];
        $methods[0]['controller'] = 'did:web:elsewhere.example';
        $doc['verificationMethod'] = $methods;
        $result = self::verify(self::f12()['genuine'], $doc);
        $this->assertSame('fail', $result->companySignature);
    }

    public function testAMethodNamedUnderAnotherDidNeverChecksTheReceiptEvenOneTheIssuerControlsAndListsByFragment(): void
    {
        $doc = self::f12()['issuerDocument'];
        $foreign = 'did:web:elsewhere.example#kms-1';
        /** @var list<array<string,mixed>> $methods */
        $methods = $doc['verificationMethod'];
        $methods[] = ['id' => $foreign] + $methods[0];
        $doc['verificationMethod'] = $methods;
        $doc['assertionMethod'] = ['#kms-1'];
        $receipt = self::f12()['genuine'];
        /** @var list<array<string,mixed>> $proof */
        $proof = $receipt['proof'];
        $proof[0]['verificationMethod'] = $foreign;
        $receipt['proof'] = $proof;
        $result = self::verify($receipt, $doc);
        $this->assertSame('fail', $result->companySignature);
        $this->assertSame('failed', $result->overall);
    }

    public function testARelativeFragmentUnderAssertionMethodResolvesOnlyAsTheIssuerPlusThatFragment(): void
    {
        $doc = self::f12()['issuerDocument'];
        /** @var list<array<string,mixed>> $methods */
        $methods = $doc['verificationMethod'];
        $methods[0]['id'] = self::issuer() . '#kms-1#x';
        $doc['verificationMethod'] = $methods;
        $doc['assertionMethod'] = ['#kms-1'];
        $result = self::verify(self::labelled(self::f12()['genuine'], '#kms-1#x'), $doc);
        $this->assertSame('fail', $result->companySignature);
    }

    public function testARelativeFragmentUnderAssertionMethodNamesTheIssuersOwnMethod(): void
    {
        $doc = self::f12()['issuerDocument'];
        $doc['assertionMethod'] = ['#kms-1'];
        $result = self::verify(self::f12()['genuine'], $doc);
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('verified', $result->overall);
    }

    public function testAnIssuerDocumentThatDoesNotResolveStaysUnavailable(): void
    {
        $result = Agreely::verifyReceipt(self::f12()['recoverySigned'], ['resolver' => static fn (string $did): ?array => null]);
        $this->assertSame('unavailable', $result->companySignature);
    }

    public function testAPasskeyBundleWhoseOfferIsSignedWithARecoveryKeyFails(): void
    {
        /** @var array<string,array<string,mixed>> $docs */
        $docs = self::vector('did-documents');
        $bundle = self::vector('a1v1.bundle');
        /** @var array<string,mixed> $receipt */
        $receipt = $bundle['receipt'];
        $issuer = Wire::str($receipt['issuer']);
        $recovery = new TestIssuer($issuer);
        $doc = $docs[$issuer];
        /** @var list<array<string,mixed>> $methods */
        $methods = $doc['verificationMethod'];
        /** @var list<array<string,mixed>> $recoveryMethods */
        $recoveryMethods = $recovery->didDoc['verificationMethod'];
        $methods[] = ['id' => $issuer . '#recovery-1'] + $recoveryMethods[0];
        $doc['verificationMethod'] = $methods;
        $doc['capabilityInvocation'] = ['#recovery-1'];
        $proof = $recovery->proofOver($bundle['signedOffer']);
        $proof['verificationMethod'] = $issuer . '#recovery-1';
        /** @var list<mixed> $proofs */
        $proofs = $receipt['proof'];
        $proofs[0] = $proof;
        $receipt['proof'] = $proofs;
        $bundle['receipt'] = $receipt;
        $result = Agreely::verifyReceipt($bundle, [
            'resolver' => static fn (string $did): ?array => $did === $issuer ? $doc : ($docs[$did] ?? null),
        ]);
        $this->assertSame('fail', $result->companySignature);
        $this->assertSame('failed', $result->overall);
    }
}
