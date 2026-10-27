<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Verify\ReceiptVerification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * No signature covers a proof's own members, so the verifier holds the proof array to the shape the issuer writes: a
 * passkey receipt carries exactly the company's DataIntegrityProof then the person's WebAuthnAssertion, a paper or
 * telephone receipt exactly the company's proof, each with exactly its issued members, all strings. The members the
 * issuer copies from a signed value must equal it: a passkey proof[0].created is the offer's createdAt and
 * proof[1].nonce its nonce, a recorded v2 to v4 proof[0].created is its recordedAt. A v1 recorded receipt pins nothing.
 * The same cases as the TS SDK's verifyProofShape.test.ts.
 */
final class ProofShapeTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function vector(string $rel): array
    {
        /** @var array<string,mixed> $v */
        $v = json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/vectors/{$rel}"), true);
        return $v;
    }

    /**
     * @param callable(array<string,mixed>): array<string,mixed> $edit
     * @return array<string,mixed>
     */
    private static function bundle(callable $edit): array
    {
        $b = self::vector('receipts/a1v1.bundle.json');
        /** @var array<string,mixed> $receipt */
        $receipt = $b['receipt'];
        $b['receipt'] = $edit($receipt);
        return $b;
    }

    /**
     * @param callable(array<string,mixed>): array<string,mixed> $edit
     * @return array<string,mixed>
     */
    private static function paper(callable $edit): array
    {
        /** @var array<string,mixed> $genuine */
        $genuine = self::vector('receipts/f12-recovery-key.json')['genuine'];
        return $edit($genuine);
    }

    /**
     * @param array<string,mixed> $r
     * @param callable(list<array<string,mixed>>): mixed $edit
     * @return array<string,mixed>
     */
    private static function withProofs(array $r, callable $edit): array
    {
        /** @var list<array<string,mixed>> $proof */
        $proof = $r['proof'];
        $r['proof'] = $edit($proof);
        return $r;
    }

    /**
     * `$proof` with `$patch` written over it.
     *
     * @param array<string,mixed> $patch
     * @return array<array-key,mixed>
     */
    private static function patched(mixed $proof, array $patch): array
    {
        self::assertIsArray($proof);
        return $patch + $proof;
    }

    private static function verifyBundle(mixed $b): ReceiptVerification
    {
        /** @var array<string,array<string,mixed>> $docs */
        $docs = self::vector('receipts/did-documents.json');
        return Agreely::verifyReceipt($b, ['resolver' => static fn (string $did): ?array => $docs[$did] ?? null]);
    }

    /** @param array<string,mixed> $r */
    private static function verifyPaper(array $r): ReceiptVerification
    {
        /** @var array<string,mixed> $doc */
        $doc = self::vector('receipts/f12-recovery-key.json')['issuerDocument'];
        $issuer = $r['issuer'];
        return Agreely::verifyReceipt($r, ['resolver' => static fn (string $did): ?array => $did === $issuer ? $doc : null]);
    }

    private static function integrityNote(ReceiptVerification $result): string
    {
        foreach ($result->notes as $note) {
            if (str_starts_with($note, 'Receipt integrity')) {
                return $note;
            }
        }
        return '';
    }

    public function testTheGenuineBundleVerifies(): void
    {
        $result = self::verifyBundle(self::bundle(static fn (array $r): array => $r));
        $this->assertSame('pass', $result->receiptIntegrity);
        $this->assertSame('verified', $result->overall);
    }

    /** @return iterable<string, array{callable(list<array<string,mixed>>): mixed}> */
    public static function malformedPasskeyProofs(): iterable
    {
        yield 'a third proof' => [static fn (array $p): array => [...$p, $p[0]]];
        yield 'only the company proof' => [static fn (array $p): array => [$p[0]]];
        yield 'the two proofs swapped' => [static fn (array $p): array => [$p[1], $p[0]]];
        yield 'an extra member on proof[0]' => [static fn (array $p): array => [self::patched($p[0], ['note' => 'x']), $p[1]]];
        yield 'an extra member on proof[1]' => [static fn (array $p): array => [$p[0], self::patched($p[1], ['note' => 'x'])]];
        yield 'a proof[0] member that is not a string' => [static fn (array $p): array => [self::patched($p[0], ['created' => 1]), $p[1]]];
        yield 'a proof[0] of another purpose' => [static fn (array $p): array => [self::patched($p[0], ['proofPurpose' => 'authentication']), $p[1]]];
        yield 'a proof[1] under another DID' => [
            static fn (array $p): array => [$p[0], self::patched($p[1], ['verificationMethod' => 'did:agreely:citizen:other#passkey-1'])],
        ];
        yield 'a proof array that is an object' => [static fn (array $p): array => ['a' => $p[0], 'b' => $p[1]]];
    }

    /** @param callable(list<array<string,mixed>>): mixed $edit */
    #[DataProvider('malformedPasskeyProofs')]
    public function testAMalformedPasskeyProofArrayFailsItsIntegrity(callable $edit): void
    {
        $result = self::verifyBundle(self::bundle(static fn (array $r): array => self::withProofs($r, $edit)));
        $this->assertSame('fail', $result->receiptIntegrity);
        $this->assertSame('failed', $result->overall);
        $this->assertStringContainsString('Receipt integrity FAILED: the proof array is not', self::integrityNote($result));
    }

    public function testAProofCreatedMovedOffTheOffersCreatedAtFailsTheSignaturesIntact(): void
    {
        $result = self::verifyBundle(self::bundle(static fn (array $r): array => self::withProofs(
            $r,
            static fn (array $p): array => [self::patched($p[0], ['created' => '2020-01-01T00:00:00Z']), $p[1]],
        )));
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('pass', $result->citizenAssertion);
        $this->assertSame('fail', $result->receiptIntegrity);
        $this->assertSame('failed', $result->overall);
        $this->assertSame("Receipt integrity FAILED: proof[0].created differs from the signed offer's createdAt.", self::integrityNote($result));
    }

    public function testAProofNonceMovedOffTheOffersNonceFails(): void
    {
        $result = self::verifyBundle(self::bundle(static fn (array $r): array => self::withProofs(
            $r,
            static fn (array $p): array => [$p[0], self::patched($p[1], ['nonce' => '0x' . str_repeat('00', 16)])],
        )));
        $this->assertSame('fail', $result->receiptIntegrity);
        $this->assertSame('failed', $result->overall);
        $this->assertSame("Receipt integrity FAILED: proof[1].nonce differs from the signed offer's nonce.", self::integrityNote($result));
    }

    public function testAnOfferWithoutCreatedAtPinsNoCreated(): void
    {
        $b = self::bundle(static fn (array $r): array => self::withProofs(
            $r,
            static fn (array $p): array => [self::patched($p[0], ['created' => '2020-01-01T00:00:00Z']), $p[1]],
        ));
        /** @var array<string,mixed> $offer */
        $offer = $b['signedOffer'];
        unset($offer['createdAt']);
        $b['signedOffer'] = $offer;
        $this->assertStringNotContainsString('created', self::integrityNote(self::verifyBundle($b)));
    }

    public function testTheGenuineV3PaperReceiptVerifies(): void
    {
        $result = self::verifyPaper(self::paper(static fn (array $r): array => $r));
        $this->assertSame('pass', $result->receiptIntegrity);
        $this->assertSame('verified', $result->overall);
    }

    /** @return iterable<string, array{callable(list<array<string,mixed>>): mixed}> */
    public static function malformedRecordedProofs(): iterable
    {
        yield 'a second proof' => [static fn (array $p): array => [$p[0], $p[0]]];
        yield 'no proof' => [static fn (array $p): array => []];
        yield 'an extra member on proof[0]' => [static fn (array $p): array => [self::patched($p[0], ['note' => 'x'])]];
        yield 'a proof[0] of another type' => [static fn (array $p): array => [self::patched($p[0], ['type' => 'Ed25519Signature2020'])]];
    }

    /** @param callable(list<array<string,mixed>>): mixed $edit */
    #[DataProvider('malformedRecordedProofs')]
    public function testAMalformedRecordedProofArrayFailsItsIntegrity(callable $edit): void
    {
        $result = self::verifyPaper(self::paper(static fn (array $r): array => self::withProofs($r, $edit)));
        $this->assertSame('fail', $result->receiptIntegrity);
        $this->assertSame('failed', $result->overall);
    }

    public function testAV3ProofCreatedMovedOffRecordedAtFailsTheSignatureIntact(): void
    {
        $result = self::verifyPaper(self::paper(static fn (array $r): array => self::withProofs(
            $r,
            static fn (array $p): array => [self::patched($p[0], ['created' => '2026-10-26T10:00:00Z'])],
        )));
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('fail', $result->receiptIntegrity);
        $this->assertSame('failed', $result->overall);
        $this->assertSame("Receipt integrity FAILED: proof[0].created differs from the consent's recordedAt.", self::integrityNote($result));
    }

    public function testTheStructureIsRefusedOfflineAnUnresolvedIssuerStillReadsFailed(): void
    {
        $r = self::paper(static fn (array $r): array => self::withProofs($r, static fn (array $p): array => [$p[0], $p[0]]));
        $result = Agreely::verifyReceipt($r, ['resolver' => static fn (string $did): ?array => null]);
        $this->assertSame('unavailable', $result->companySignature);
        $this->assertSame('failed', $result->overall);
    }

    public function testAV1ReceiptPinsNoCreated(): void
    {
        /** @var array{cases: list<array{receipt: array<string,mixed>}>, fixtures: array{didDocuments: array<string,array<string,mixed>>}} $rv */
        $rv = self::vector('vectors.json')['receiptVerification'];
        $r = $rv['cases'][0]['receipt'];
        $docs = $rv['fixtures']['didDocuments'];
        $this->assertArrayNotHasKey('receiptVersion', $r);
        $result = Agreely::verifyReceipt($r, ['resolver' => static fn (string $did): ?array => $docs[$did] ?? null]);
        $this->assertSame('pass', $result->receiptIntegrity);
        $this->assertSame('verified', $result->overall);
    }

    public function testABarePasskeyReceiptWhoseAssertionSitsAtProof2ReadsNoAssertion(): void
    {
        $r = self::withProofs(self::vector('receipts/a1v1.receipt.json'), static fn (array $p): array => [$p[0], $p[0], $p[1]]);
        $result = self::verifyBundle($r);
        $this->assertSame('fail', $result->citizenAssertion);
        $this->assertContains('Citizen assertion FAILED: no WebAuthn assertion or citizen DID on the receipt.', $result->notes);
    }
}
