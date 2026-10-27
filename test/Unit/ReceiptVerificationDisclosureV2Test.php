<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Crypto\Canonicalizer;
use Agreely\Sdk\Verify\ReceiptVerification;
use PHPUnit\Framework\TestCase;

/**
 * A v2 consent: at least one cell rests on a non-consent basis, so the app seals the disclosureHash over
 * {disclosure, legalBasis} and stamps disclosureVersion "2" (ConsentRequestService, the v2 branch). On a
 * passkey consent only the OFFER is company-signed: the copy is checked against the offer's hash and basis.
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

    private const DOCUMENT = [
        'code' => 'marketing',
        'name' => 'Marketing',
        'version' => '1.0',
        'effectiveDate' => '2026-01-01',
        'ipfsCid' => self::CID,
    ];

    private const ITEMS = [['itemId' => '0x01', 'category' => 'email address', 'purpose' => 'marketing outreach']];
    private const GRANTED_AT = '2026-01-15T12:00:00Z';
    private const FORGED_BASIS = ['0x01' => ['basis' => 'contract', 'justificationFr' => 'Contrat.', 'justificationEn' => 'Contract.']];

    private TestIssuer $company;

    protected function setUp(): void
    {
        $this->company = new TestIssuer();
    }

    private static function sha256Of(mixed $preimage): string
    {
        return '0x' . hash('sha256', (new Canonicalizer())->encode($preimage));
    }

    /**
     * A company-signed v2 offer (edit it before signing for a v1 or another variant).
     *
     * @param (callable(array<string,mixed>): array<string,mixed>)|null $edit
     * @return array<string,mixed>
     */
    private function offer(?callable $edit = null): array
    {
        $o = [
            'issuer' => $this->company->issuer,
            'requestId' => '0x' . str_repeat('cd', 32),
            'nonce' => '0x' . str_repeat('ef', 32),
            'validUntil' => '',
            'consent' => ['action' => 'grant', 'items' => self::ITEMS],
            'document' => self::DOCUMENT,
            'disclosure' => self::DISCLOSURE,
            'disclosureHash' => self::PHP_V2_DISCLOSURE_HASH,
            'disclosureVersion' => '2',
            'legalBasis' => self::LEGAL_BASIS,
        ];
        return $edit === null ? $o : $edit($o);
    }

    /**
     * The passkey receipt the app derives from `$o`, signed (proof[0]) over the offer, in its bundle. proof[1] has the
     * issued shape but signs nothing: only the company half matters here.
     *
     * @param array<string,mixed> $o
     * @param (callable(array<string,mixed>): array<string,mixed>)|null $editConsent
     * @return array<string,mixed>
     */
    private function bundleOf(array $o, ?callable $editConsent = null): array
    {
        $consent = [
            'action' => 'grant',
            'items' => self::ITEMS,
            'requestId' => $o['requestId'],
            'grantedAt' => self::GRANTED_AT,
            'document' => $o['document'],
            'disclosureHash' => $o['disclosureHash'],
        ];
        if (array_key_exists('legalBasis', $o)) {
            $consent['disclosureVersion'] = $o['disclosureVersion'];
            $consent['legalBasis'] = $o['legalBasis'];
        }
        if ($editConsent !== null) {
            $consent = $editConsent($consent);
        }
        return [
            'version' => '1',
            'receipt' => [
                '@context' => ['https://www.w3.org/ns/credentials/v2', 'https://agreely.ca/credentials/consent/v1'],
                'type' => ['VerifiableCredential', 'ConsentReceipt'],
                'id' => 'urn:agreely:receipt:00000000-0000-4000-8000-000000000002',
                'issuer' => $this->company->issuer,
                'validFrom' => self::GRANTED_AT,
                'validUntil' => '',
                'credentialSubject' => ['id' => 'did:agreely:citizen:v2', 'consent' => $consent],
                'proof' => [
                    $this->company->proofOver($o),
                    [
                        'type' => 'WebAuthnAssertion',
                        'verificationMethod' => 'did:agreely:citizen:v2#passkey-1',
                        'nonce' => $o['nonce'],
                        'challenge' => '0x' . str_repeat('00', 32),
                        'authenticatorData' => '',
                        'clientDataJSON' => '',
                        'signature' => '',
                    ],
                ],
            ],
            'signedOffer' => $o,
            'merkleRoot' => '0x' . str_repeat('ab', 32),
        ];
    }

    /**
     * A company-attested receipt carrying a CID and a disclosureHash, signed over its body.
     *
     * @param callable(array<string,mixed>): array<string,mixed> $edit
     * @return array<string,mixed>
     */
    private function recorded(callable $edit): array
    {
        $consent = $edit([
            'action' => 'grant',
            'items' => self::ITEMS,
            'document' => self::DOCUMENT,
            'grantedAt' => self::GRANTED_AT,
            'disclosureHash' => self::PHP_V2_DISCLOSURE_HASH,
            'disclosureVersion' => '2',
            'legalBasis' => self::LEGAL_BASIS,
        ]);
        $body = [
            'type' => ['VerifiableCredential', 'ConsentReceipt', 'CompanyAttestedConsentReceipt'],
            'issuer' => $this->company->issuer,
            'assuranceLevel' => 'company_attested',
            'validFrom' => self::GRANTED_AT,
            'credentialSubject' => ['consent' => $consent],
            'evidence' => ['type' => 'HandSignedPaperConsent', 'pdfHash' => '0x' . str_repeat('11', 32)],
        ];
        return $body + ['proof' => [$this->company->proofOver($body)]];
    }

    /**
     * Runs the verifier with the disclosure check requested and the IPFS body stubbed.
     *
     * @param array<string,mixed> $input
     */
    private function verifyWithCopy(array $input, mixed $body = self::IPFS_BODY): ReceiptVerification
    {
        return Agreely::verifyReceipt($input, [
            'resolver' => $this->company->resolver(),
            'verifyDisclosure' => true,
            'ipfsGateway' => static fn (string $cid): string => "https://ipfs.test/{$cid}",
            'httpGet' => static fn (string $url): string => (string) json_encode($body),
        ]);
    }

    private static function notesOf(ReceiptVerification $result): string
    {
        return implode("\n", $result->notes);
    }

    // --- a passkey bundle: the copy is checked against the SIGNED offer ----------

    public function testThePhpFormulaReproducesTheAppV2HashAndTheV1FormulaDoesNot(): void
    {
        $this->assertSame(self::PHP_V2_DISCLOSURE_HASH, self::sha256Of(['disclosure' => self::DISCLOSURE, 'legalBasis' => self::LEGAL_BASIS]));
        $this->assertNotSame(self::PHP_V2_DISCLOSURE_HASH, self::sha256Of(self::DISCLOSURE));
    }

    public function testAV2BundleWithAnAuthenticBodyPassesAndTheNoteNamesTheVersion(): void
    {
        $result = $this->verifyWithCopy($this->bundleOf($this->offer()));
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('pass', $result->receiptIntegrity);
        $this->assertSame('pass', $result->disclosureCopy);
        $this->assertStringContainsString('disclosureVersion 2', self::notesOf($result));
    }

    public function testAV2BundleWhoseIpfsDisclosureWasTamperedFails(): void
    {
        $tampered = self::IPFS_BODY;
        $tampered['disclosure']['purpose']['en'] = 'Marketing, shared with partners.';
        $this->assertSame('fail', $this->verifyWithCopy($this->bundleOf($this->offer()), $tampered)->disclosureCopy);
    }

    public function testAnIpfsBodyCarryingALegalBasisThatDiffersFromTheSignedOneFails(): void
    {
        $body = self::IPFS_BODY + ['legalBasis' => ['0x01' => ['basis' => 'consent', 'justificationFr' => '']]];
        $result = $this->verifyWithCopy($this->bundleOf($this->offer()), $body);
        $this->assertSame('fail', $result->disclosureCopy);
        $this->assertStringContainsString('differs from the signed one', self::notesOf($result));
    }

    public function testAnIpfsBodyCarryingTheSignedLegalBasisStillPasses(): void
    {
        $body = self::IPFS_BODY + ['legalBasis' => self::LEGAL_BASIS];
        $this->assertSame('pass', $this->verifyWithCopy($this->bundleOf($this->offer()), $body)->disclosureCopy);
    }

    public function testAV1OfferPassesOnTheDisclosureAlone(): void
    {
        $v1 = $this->offer(static function (array $o): array {
            unset($o['legalBasis']);
            $o['disclosureVersion'] = '1';
            $o['disclosureHash'] = self::sha256Of(self::DISCLOSURE);
            return $o;
        });
        $result = $this->verifyWithCopy($this->bundleOf($v1));
        $this->assertSame('pass', $result->receiptIntegrity);
        $this->assertSame('pass', $result->disclosureCopy);
    }

    public function testAReceiptWhoseLegalBasisWasRewrittenWithARecomputedHashFailsItsIntegrity(): void
    {
        $forged = $this->bundleOf($this->offer(), static function (array $c): array {
            $c['legalBasis'] = self::FORGED_BASIS;
            $c['disclosureHash'] = self::sha256Of(['disclosure' => self::DISCLOSURE, 'legalBasis' => self::FORGED_BASIS]);
            return $c;
        });
        $result = $this->verifyWithCopy($forged);
        $this->assertSame('fail', $result->receiptIntegrity);
        $this->assertSame('failed', $result->overall);
        $this->assertStringContainsString('matches the signed disclosureHash ' . self::PHP_V2_DISCLOSURE_HASH, self::notesOf($result));
    }

    public function testAnOfferWhoseSignatureCannotBeCheckedIsNotCompared(): void
    {
        $result = Agreely::verifyReceipt($this->bundleOf($this->offer()), [
            'resolver' => static fn (string $did): ?array => null,
            'verifyDisclosure' => true,
            'httpGet' => static fn (string $url): string => (string) json_encode(self::IPFS_BODY),
        ]);
        $this->assertSame('skipped', $result->disclosureCopy);
        $this->assertStringContainsString('no signed disclosureHash', self::notesOf($result));
    }

    public function testAnIpfsBodyWhoseDisclosureHoldsANumberFailsAndDoesNotThrow(): void
    {
        $body = self::IPFS_BODY;
        $body['disclosure']['copies'] = 3;
        $result = $this->verifyWithCopy($this->bundleOf($this->offer()), $body);
        $this->assertSame('fail', $result->disclosureCopy);
        $this->assertStringContainsString('cannot be canonicalized', self::notesOf($result));
    }

    // --- opt-in, and a gateway error is never a hash mismatch ---------------------

    public function testTheCheckIsNotRunAndTheGatewayNeverCalledUnlessRequested(): void
    {
        $fetched = [];
        $result = Agreely::verifyReceipt($this->bundleOf($this->offer()), [
            'resolver' => $this->company->resolver(),
            'httpGet' => static function (string $url) use (&$fetched): string {
                $fetched[] = $url;
                return (string) json_encode(self::IPFS_BODY);
            },
        ]);
        $this->assertSame('skipped', $result->disclosureCopy);
        $this->assertSame([], $fetched);
        $this->assertStringContainsString('verifyDisclosure: true', self::notesOf($result));
    }

    public function testAFailedOrUnreachableGatewayIsUnavailable(): void
    {
        foreach (
            [
            static fn (string $url): ?string => null,
            static function (string $url): string {
                throw new \RuntimeException('gateway down');
            },
            static fn (string $url): string => '<html>502 Bad Gateway</html>',
            ] as $get
        ) {
            $result = Agreely::verifyReceipt($this->bundleOf($this->offer()), [
                'resolver' => $this->company->resolver(),
                'verifyDisclosure' => true,
                'httpGet' => $get,
            ]);
            $this->assertSame('unavailable', $result->disclosureCopy);
            $this->assertStringContainsString('This is NOT a hash mismatch', self::notesOf($result));
        }
    }

    // --- a BARE passkey receipt never passes its disclosure copy -----------------

    public function testABareV2ReceiptWithARewrittenBasisAndARecomputedHashIsUnsupportedAndNeverFetched(): void
    {
        $bundle = $this->bundleOf($this->offer(), static function (array $c): array {
            $c['legalBasis'] = self::FORGED_BASIS;
            $c['disclosureHash'] = self::sha256Of(['disclosure' => self::DISCLOSURE, 'legalBasis' => self::FORGED_BASIS]);
            return $c;
        });
        $fetched = [];
        /** @var array<string,mixed> $receipt */
        $receipt = $bundle['receipt'];
        $result = Agreely::verifyReceipt($receipt, [
            'resolver' => $this->company->resolver(),
            'verifyDisclosure' => true,
            'httpGet' => static function (string $url) use (&$fetched): string {
                $fetched[] = $url;
                return (string) json_encode(self::IPFS_BODY);
            },
        ]);
        $this->assertSame('unsupported', $result->disclosureCopy);
        $this->assertNotSame('verified', $result->overall);
        $this->assertSame([], $fetched);
    }

    // --- a RECORDED receipt reads the version and basis under the company signature

    public function testAnAuthenticV2RecordedReceiptPasses(): void
    {
        $result = $this->verifyWithCopy($this->recorded(static fn (array $c): array => $c));
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('pass', $result->disclosureCopy);
    }

    public function testAV2RecordedReceiptWithATamperedJustificationFails(): void
    {
        $result = $this->verifyWithCopy($this->recorded(static function (array $c): array {
            $c['legalBasis'] = ['0x01' => ['justificationEn' => 'Something else.'] + self::LEGAL_BASIS['0x01']];
            return $c;
        }));
        $this->assertSame('pass', $result->companySignature);
        $this->assertSame('fail', $result->disclosureCopy);
    }

    public function testAV2RecordedReceiptWithNoLegalBasisFailsAndNeverFallsBackToTheV1Formula(): void
    {
        $result = $this->verifyWithCopy($this->recorded(static function (array $c): array {
            unset($c['legalBasis']);
            $c['disclosureHash'] = self::sha256Of(self::DISCLOSURE);
            return $c;
        }));
        $this->assertSame('fail', $result->disclosureCopy);
        $this->assertStringContainsString('carries no legalBasis', self::notesOf($result));
    }

    public function testAV2RecordedReceiptWithANullOrListLegalBasisFailsAsAMissingMap(): void
    {
        foreach ([null, ['x']] as $basis) {
            $result = $this->verifyWithCopy($this->recorded(static function (array $c) use ($basis): array {
                $c['legalBasis'] = $basis;
                return $c;
            }));
            $this->assertSame('fail', $result->disclosureCopy);
            $this->assertStringContainsString('carries no legalBasis', self::notesOf($result));
        }
    }

    public function testALegalBasisWithoutDisclosureVersion2FailsBecauseThePreimageIsAmbiguous(): void
    {
        $result = $this->verifyWithCopy($this->recorded(static function (array $c): array {
            $c['disclosureVersion'] = '1';
            return $c;
        }));
        $this->assertSame('fail', $result->disclosureCopy);
    }

    public function testAnUnknownOrNullDisclosureVersionFails(): void
    {
        foreach (['3', null] as $version) {
            $result = $this->verifyWithCopy($this->recorded(static function (array $c) use ($version): array {
                $c['disclosureVersion'] = $version;
                return $c;
            }));
            $this->assertSame('fail', $result->disclosureCopy);
            $this->assertStringContainsString('unknown disclosureVersion', self::notesOf($result));
        }
    }

    public function testAnExplicitOrAbsentDisclosureVersion1WithNoLegalBasisPassesOnTheDisclosureAlone(): void
    {
        foreach (['1', null] as $version) {
            $result = $this->verifyWithCopy($this->recorded(static function (array $c) use ($version): array {
                unset($c['legalBasis'], $c['disclosureVersion']);
                if ($version !== null) {
                    $c['disclosureVersion'] = $version;
                }
                $c['disclosureHash'] = self::sha256Of(self::DISCLOSURE);
                return $c;
            }));
            $this->assertSame('pass', $result->disclosureCopy);
        }
    }
}
