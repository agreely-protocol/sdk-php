<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Crypto\Canonicalizer;
use Agreely\Sdk\Verify\ReceiptVerification;
use PHPUnit\Framework\TestCase;

/**
 * A v2 receipt: at least one cell rests on a non-consent basis, so the app seals the disclosureHash over
 * {disclosure, legalBasis} and stamps disclosureVersion "2" (ConsentRequestService, the v2 branch).
 */
final class ReceiptVerificationDisclosureV2Test extends TestCase
{
    private const DISCLOSURE = [
        'purpose' => ['fr' => 'Fins de prestation du service demandé.', 'en' => 'Purposes of the requested service.'],
        'responsable' => ['name' => 'Acme Responsable de la protection', 'contact' => 'privacy@acme.example'],
    ];

    private const LEGAL_BASIS = [
        '0x01' => [
            'basis' => 'necessary_for_service',
            'justificationFr' => 'Nécessaire à la prestation du service demandé.',
            'justificationEn' => 'Necessary to provide the requested service.',
        ],
    ];

    // Computed by the APP's own canonicalizer, App\Models\Crypto\Canonicalizer (the class ConsentRequestService seals
    // with), not by this SDK:
    //   docker compose exec -T web php -r 'require "vendor/autoload.php"; $c = new App\Models\Crypto\Canonicalizer();
    //     $disclosure = [...]; $legalBasis = [...]; $preimage = $c->encode(["disclosure" => $disclosure,
    //     "legalBasis" => $legalBasis]); echo "0x" . hash("sha256", $preimage);'
    // The PHP formula must reproduce this byte for byte, which is what proves cross-language parity.
    private const PHP_V2_DISCLOSURE_HASH = '0xa589592c89690e3cb2cb8ee4bcf2599fb0ba776372db0203d5463860ffa968bc';

    private const CID = 'bafkreiv2000000000000000000000000000000000000000000000000000001';

    // The pinned IPFS body, as IpfsDocumentService::canonicalPayload writes it: the disclosure map, and no legalBasis.
    private const IPFS_BODY = [
        'code' => 'marketing',
        'name' => 'Marketing',
        'version' => '1.0',
        'effectiveDate' => '2026-01-01',
        'disclosure' => self::DISCLOSURE,
    ];

    private static function sha256Of(mixed $preimage): string
    {
        return '0x' . hash('sha256', (new Canonicalizer())->encode($preimage));
    }

    /** @return array<string,mixed> */
    private static function baseConsent(): array
    {
        return [
            'action' => 'grant',
            'items' => [['itemId' => '0x01', 'category' => 'email address', 'purpose' => 'marketing outreach']],
            'requestId' => '0x' . str_repeat('cd', 32),
            'grantedAt' => '2026-01-15T12:00:00Z',
            'document' => [
                'code' => 'marketing',
                'name' => 'Marketing',
                'version' => '1.0',
                'effectiveDate' => '2026-01-01',
                'ipfsCid' => self::CID,
            ],
            'disclosureHash' => self::PHP_V2_DISCLOSURE_HASH,
            'disclosureVersion' => '2',
            'legalBasis' => self::LEGAL_BASIS,
        ];
    }

    /**
     * A fresh receipt, with its consent block passed through $edit.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $edit
     * @return array<string,mixed>
     */
    private static function receiptWith(callable $edit): array
    {
        return [
            'type' => ['VerifiableCredential', 'ConsentReceipt'],
            'issuer' => 'did:web:api.agreely.ca:c:acme',
            'credentialSubject' => [
                'id' => 'did:agreely:citizen:v2',
                'consent' => $edit(self::baseConsent()),
            ],
            'proof' => [],
        ];
    }

    /** @return array<string,mixed> */
    private static function baseReceipt(): array
    {
        return self::receiptWith(static fn (array $consent): array => $consent);
    }

    /**
     * Runs the verifier with the IPFS body stubbed. Only the disclosureCopy check is asserted on.
     *
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $body
     */
    private static function disclosureCopyOf(array $receipt, array $body = self::IPFS_BODY): ReceiptVerification
    {
        return Agreely::verifyReceipt($receipt, [
            'resolver' => static fn (string $did): ?array => null,
            'ipfsGateway' => static fn (string $cid): string => "https://ipfs.test/{$cid}",
            'httpGet' => static fn (string $url): string => (string) json_encode($body),
        ]);
    }

    private static function notesOf(ReceiptVerification $result): string
    {
        return implode("\n", $result->notes);
    }

    public function testTheSdkFormulaReproducesTheAppsV2HashAndTheV1FormulaDoesNot(): void
    {
        $this->assertSame(
            self::PHP_V2_DISCLOSURE_HASH,
            self::sha256Of(['disclosure' => self::DISCLOSURE, 'legalBasis' => self::LEGAL_BASIS]),
        );
        $this->assertNotSame(self::PHP_V2_DISCLOSURE_HASH, self::sha256Of(self::DISCLOSURE));
    }

    public function testAV2ReceiptWithAnAuthenticBodyAndLegalBasisPasses(): void
    {
        $result = self::disclosureCopyOf(self::baseReceipt());

        $this->assertSame('pass', $result->disclosureCopy);
        $this->assertStringContainsString('disclosureVersion 2', self::notesOf($result));
    }

    public function testAV2ReceiptWithATamperedJustificationFails(): void
    {
        $tamperedBasis = self::LEGAL_BASIS;
        $tamperedBasis['0x01']['justificationFr'] = 'Consentement obtenu de force.';
        $receipt = self::receiptWith(static function (array $consent) use ($tamperedBasis): array {
            $consent['legalBasis'] = $tamperedBasis;
            return $consent;
        });
        $result = self::disclosureCopyOf($receipt);

        $this->assertSame('fail', $result->disclosureCopy);
        $this->assertStringContainsString('disclosureVersion 2', self::notesOf($result));
    }

    public function testAV2ReceiptWhoseDisclosureBodyWasTamperedFails(): void
    {
        $tampered = self::IPFS_BODY;
        $tampered['disclosure']['purpose']['en'] = 'Marketing, shared with partners.';

        $result = self::disclosureCopyOf(self::baseReceipt(), $tampered);

        $this->assertSame('fail', $result->disclosureCopy);
    }

    public function testAV2ReceiptWithNoLegalBasisFailsAndNeverFallsBackToTheV1Formula(): void
    {
        // The hash here is the v1 formula over the disclosure alone: a fallback would wrongly pass it.
        $receipt = self::receiptWith(static function (array $consent): array {
            unset($consent['legalBasis']);
            $consent['disclosureHash'] = self::sha256Of(self::DISCLOSURE);
            return $consent;
        });
        $result = self::disclosureCopyOf($receipt);

        $this->assertSame('fail', $result->disclosureCopy);
        $this->assertStringContainsString('carries no legalBasis', self::notesOf($result));
    }

    public function testAV2ReceiptWithANullLegalBasisFailsAsAMissingMap(): void
    {
        $receipt = self::receiptWith(static function (array $consent): array {
            $consent['legalBasis'] = null;
            $consent['disclosureHash'] = self::sha256Of(self::DISCLOSURE);
            return $consent;
        });
        $result = self::disclosureCopyOf($receipt);

        $this->assertSame('fail', $result->disclosureCopy);
        $this->assertStringContainsString('carries no legalBasis', self::notesOf($result));
    }

    public function testAV2ReceiptWithAListOrEmptyLegalBasisFailsAsAMissingMap(): void
    {
        foreach ([[], ['not', 'a', 'map']] as $basis) {
            $receipt = self::receiptWith(static function (array $consent) use ($basis): array {
                $consent['legalBasis'] = $basis;
                return $consent;
            });
            $result = self::disclosureCopyOf($receipt);

            $this->assertSame('fail', $result->disclosureCopy);
            $this->assertStringContainsString('carries no legalBasis', self::notesOf($result));
        }
    }

    public function testAV2BodyThatCarriesADifferentLegalBasisFails(): void
    {
        $body = self::IPFS_BODY + ['legalBasis' => ['0x01' => ['basis' => 'consent', 'justificationFr' => '']]];

        $result = self::disclosureCopyOf(self::baseReceipt(), $body);

        $this->assertSame('fail', $result->disclosureCopy);
        $this->assertStringContainsString("differs from the receipt's", self::notesOf($result));
    }

    public function testAV2BodyThatCarriesTheSameLegalBasisAsTheReceiptStillPasses(): void
    {
        $body = self::IPFS_BODY + ['legalBasis' => self::LEGAL_BASIS];

        $result = self::disclosureCopyOf(self::baseReceipt(), $body);

        $this->assertSame('pass', $result->disclosureCopy);
    }

    public function testALegalBasisWithoutDisclosureVersion2FailsBecauseThePreimageIsAmbiguous(): void
    {
        $receipt = self::receiptWith(static function (array $consent): array {
            $consent['disclosureVersion'] = '1';
            return $consent;
        });

        $this->assertSame('fail', self::disclosureCopyOf($receipt)->disclosureCopy);
    }

    public function testAnUnknownDisclosureVersionFails(): void
    {
        $receipt = self::receiptWith(static function (array $consent): array {
            $consent['disclosureVersion'] = '3';
            return $consent;
        });
        $result = self::disclosureCopyOf($receipt);

        $this->assertSame('fail', $result->disclosureCopy);
        $this->assertStringContainsString('unknown disclosureVersion', self::notesOf($result));
    }

    public function testANullDisclosureVersionIsUnknownNotAbsent(): void
    {
        // A declared null is not the v1 absence: the receipt names a version it does not carry.
        $receipt = self::receiptWith(static function (array $consent): array {
            unset($consent['legalBasis']);
            $consent['disclosureVersion'] = null;
            $consent['disclosureHash'] = self::sha256Of(self::DISCLOSURE);
            return $consent;
        });
        $result = self::disclosureCopyOf($receipt);

        $this->assertSame('fail', $result->disclosureCopy);
        $this->assertStringContainsString('unknown disclosureVersion', self::notesOf($result));
    }

    public function testAV1ReceiptWithNoLegalBasisAndNoVersionStillPassesOnTheDisclosureAlone(): void
    {
        $receipt = self::receiptWith(static function (array $consent): array {
            unset($consent['legalBasis'], $consent['disclosureVersion']);
            $consent['disclosureHash'] = self::sha256Of(self::DISCLOSURE);
            return $consent;
        });
        $result = self::disclosureCopyOf($receipt);

        $this->assertSame('pass', $result->disclosureCopy);
        $this->assertStringNotContainsString('disclosureVersion 2', self::notesOf($result));
    }

    public function testAnExplicitDisclosureVersion1WithNoLegalBasisStillPasses(): void
    {
        $receipt = self::receiptWith(static function (array $consent): array {
            unset($consent['legalBasis']);
            $consent['disclosureVersion'] = '1';
            $consent['disclosureHash'] = self::sha256Of(self::DISCLOSURE);
            return $consent;
        });

        $this->assertSame('pass', self::disclosureCopyOf($receipt)->disclosureCopy);
    }
}
