<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Errors\ErrorCode;
use Agreely\Sdk\Errors\ErrorReason;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\AcknowledgedLine;
use Agreely\Sdk\Types\ClaimLink;
use Agreely\Sdk\Types\ConsentSheet;
use Agreely\Sdk\Types\ManualConsentErasure;
use Agreely\Sdk\Types\ManualConsentResult;
use Agreely\Sdk\Types\ManualConsentRevocation;
use PHPUnit\Framework\TestCase;

final class ManualConsentsTest extends TestCase
{
    private const REF = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA = '0xffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http]);
    }

    /** @return array{customerId:string,documentVersionId:string,effectiveDate:string,validUntil:string,items:list<string|array{category:string,purpose:string}>,evidence:array{pdfSha256:string,pdf?:string}} */
    private function input(): array
    {
        return [
            'customerId' => 'cust',
            'documentVersionId' => 'doc-1',
            'effectiveDate' => '2026-06-01',
            'validUntil' => '2031-01-01',
            'items' => ['0xcat', ['category' => 'Email', 'purpose' => 'News']],
            'evidence' => ['pdfSha256' => self::SHA],
        ];
    }

    private static function recorded(): MockHttpClient
    {
        return new MockHttpClient([
            MockHttpClient::json(201, [
                'consentId' => 'mc_1',
                'merkleRoot' => '0x' . str_repeat('1', 64),
                'consentRefs' => [self::REF],
                'assurance' => 'company_attested',
                'anchored' => false,
            ]),
        ]);
    }

    public function testRecordReturnsTheCompanyAttestedResult(): void
    {
        $http = self::recorded();
        $r = $this->client($http)->manualConsents()->record($this->input());
        $this->assertInstanceOf(ManualConsentResult::class, $r);
        $this->assertSame('company_attested', $r->assurance);
        $this->assertFalse($r->anchored);
        $this->assertSame([self::REF], $r->consentRefs);
        $this->assertSame('/v1/manual-consents', $http->calls[0]->path());
    }

    public function testRecordAutoGeneratesAnIdempotencyKey(): void
    {
        $http = self::recorded();
        $this->client($http)->manualConsents()->record($this->input());
        $key = $http->calls[0]->header('Idempotency-Key');
        $this->assertNotNull($key);
        $this->assertStringStartsWith('idem_', $key);
    }

    public function testRecordAutoKeyIsUniquePerCall(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(201, ['consentId' => 'mc', 'merkleRoot' => '0x', 'consentRefs' => [], 'assurance' => 'company_attested', 'anchored' => false]),
        ]);
        $client = $this->client($http);
        $client->manualConsents()->record($this->input());
        $client->manualConsents()->record($this->input());
        $this->assertNotSame(
            $http->calls[0]->header('Idempotency-Key'),
            $http->calls[1]->header('Idempotency-Key'),
        );
    }

    public function testRecordHonoursAnOverriddenIdempotencyKey(): void
    {
        $http = self::recorded();
        $this->client($http)->manualConsents()->record($this->input(), ['idempotencyKey' => 'order-4471']);
        $this->assertSame('order-4471', $http->calls[0]->header('Idempotency-Key'));
    }

    public function testRecordSendsRawItemsAndTheHashCommitmentWithoutPdfBytes(): void
    {
        $http = self::recorded();
        $this->client($http)->manualConsents()->record($this->input());
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $items = $body['items'];
        $this->assertIsArray($items);
        $this->assertSame('0xcat', $items[0]);
        $this->assertIsArray($items[1]);
        $this->assertSame('Email', $items[1]['category']);
        $evidence = $body['evidence'];
        $this->assertIsArray($evidence);
        $this->assertSame(self::SHA, $evidence['pdfSha256']);
        $this->assertArrayNotHasKey('pdf', $evidence);
    }

    public function testRecordUploadsThePdfBytesOnlyWhenProvided(): void
    {
        $http = self::recorded();
        $input = $this->input();
        $input['evidence']['pdf'] = 'YmFzZTY0';
        $this->client($http)->manualConsents()->record($input);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $evidence = $body['evidence'];
        $this->assertIsArray($evidence);
        $this->assertSame('YmFzZTY0', $evidence['pdf']);
    }

    public function testCreateClaimLinkReturnsTheLink(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(201, ['claimUrl' => 'https://x/claim/abc', 'token' => 'tok_abc', 'expiresAt' => '2026-07-01T00:00:00Z']),
        ]);
        $link = $this->client($http)->manualConsents()->createClaimLink(['customerId' => 'cust', 'reference' => 'order-99']);
        $this->assertInstanceOf(ClaimLink::class, $link);
        $this->assertSame('tok_abc', $link->token);
        $this->assertSame('/v1/manual-consents/claim-links', $http->calls[0]->path());
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertSame('cust', $body['customerId']);
        $this->assertSame('order-99', $body['reference']);
    }

    public function testRevokeForwardsReasonAndIsKeyedOnTheConsentRef(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['consentRef' => self::REF, 'revoked' => true, 'alreadyRevoked' => false]),
        ]);
        $r = $this->client($http)->manualConsents()->revoke(self::REF, ['reason' => 'withdrawn']);
        $this->assertInstanceOf(ManualConsentRevocation::class, $r);
        $this->assertTrue($r->revoked);
        $this->assertFalse($r->alreadyRevoked);
        $this->assertSame('/v1/manual-consents/' . self::REF . '/revoke', $http->calls[0]->path());
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertSame('withdrawn', $body['reason']);
    }

    public function testEraseIsKeyedOnTheConsentRef(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['consentRef' => self::REF, 'erased' => true, 'alreadyErased' => true]),
        ]);
        $r = $this->client($http)->manualConsents()->erase(self::REF);
        $this->assertInstanceOf(ManualConsentErasure::class, $r);
        $this->assertTrue($r->erased);
        $this->assertTrue($r->alreadyErased);
        $this->assertSame('/v1/manual-consents/' . self::REF . '/erase', $http->calls[0]->path());
    }

    public function testCheckResultCarriesAssuranceFromWire(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'decision' => 'allow',
                'status' => 'active',
                'consentRef' => self::REF,
                'assurance' => 'company_attested',
                'checkedAt' => '2026-01-01T00:00:00Z',
            ]),
        ]);
        $r = $this->client($http)->checkDetailed('c1', 'Email', 'News');
        $this->assertSame('company_attested', $r->assurance);
    }

    public function testCheckResultAssuranceIsNullForStatusNone(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['decision' => 'deny', 'status' => 'none', 'checkedAt' => 't']),
        ]);
        $r = $this->client($http)->checkDetailed('c1', 'Email', 'News');
        $this->assertNull($r->assurance);
    }

    public function testRecordReportsTheAcknowledgedLinesAndAsksDeclined(): void
    {
        $ack = '0x' . str_repeat('b', 64);
        $http = new MockHttpClient([
            MockHttpClient::json(201, [
                'consentId' => 'mc_1', 'merkleRoot' => '0x' . str_repeat('1', 64), 'consentRefs' => [$ack],
                'assurance' => 'company_attested', 'anchored' => false,
                'acknowledged' => [['category' => 'Coordonnees', 'purpose' => 'Gestion du dossier', 'consentRef' => $ack]],
                'asksDeclined' => true,
            ]),
        ]);
        $input = $this->input();
        $input['items'] = [];
        $r = $this->client($http)->manualConsents()->record($input);
        $this->assertTrue($r->asksDeclined);
        $this->assertCount(1, $r->acknowledged);
        $this->assertInstanceOf(AcknowledgedLine::class, $r->acknowledged[0]);
        $this->assertSame($ack, $r->acknowledged[0]->consentRef);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertSame([], $body['items'], 'an empty items list is sent, not dropped');
    }

    public function testRecordRefusesAMalformedIdempotencyKeyBeforeTheCall(): void
    {
        foreach (['', 'has space', "tab\there", str_repeat('k', 256), 'caf' . "\u{e9}"] as $bad) {
            $http = self::recorded();
            try {
                $this->client($http)->manualConsents()->record($this->input(), ['idempotencyKey' => $bad]);
                $this->fail('expected AgreelyConfigError for ' . json_encode($bad));
            } catch (AgreelyConfigError $e) {
                $this->assertStringContainsString('printable ASCII', $e->getMessage());
            }
            $this->assertCount(0, $http->calls);
        }
        $http = self::recorded();
        $this->client($http)->manualConsents()->record($this->input(), ['idempotencyKey' => str_repeat('k', 255)]);
        $this->assertSame(str_repeat('k', 255), $http->calls[0]->header('Idempotency-Key'));
    }

    public function testAStateConflictIsDistinguishableFromARetry(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(409, ['error' => ['code' => 'conflict', 'message' => 'A verbal consent awaits its paper.']]),
        ]);
        try {
            $this->client($http)->manualConsents()->record($this->input());
            $this->fail('expected AgreelyConflictError');
        } catch (AgreelyConflictError $e) {
            $this->assertSame('conflict', $e->code);
            $this->assertTrue($e->isStateConflict());
            $this->assertFalse($e->isRetryable());
        }
        $this->assertCount(1, $http->calls, 'never retried');
    }

    public function testClaimLinkForAnUnknownCustomerIsNotFound(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(404, ['error' => ['code' => 'not_found', 'message' => 'No customer with that reference.']]),
        ]);
        $this->expectException(AgreelyNotFoundError::class);
        $this->client($http)->manualConsents()->createClaimLink(['customerId' => 'nobody']);
    }

    public function testClaimLinkForAnEndedRelationshipIsAConflict(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(409, ['error' => ['code' => 'conflict', 'message' => 'The relationship has ended.']]),
        ]);
        try {
            $this->client($http)->manualConsents()->createClaimLink(['customerId' => 'cust']);
            $this->fail('expected AgreelyConflictError');
        } catch (AgreelyConflictError $e) {
            $this->assertTrue($e->isStateConflict());
        }
    }

    public function testRevokeReportsWhatTheGateDoesNow(): void
    {
        foreach (['denied', 'superseded', 'unchanged'] as $gate) {
            $http = new MockHttpClient([
                MockHttpClient::json(200, [
                    'consentRef' => self::REF, 'revoked' => true, 'alreadyRevoked' => $gate === 'unchanged', 'gate' => $gate,
                ]),
            ]);
            $r = $this->client($http)->manualConsents()->revoke(self::REF);
            $this->assertSame($gate, $r->gate);
            $this->assertSame($gate === ManualConsentRevocation::GATE_DENIED, $r->gateDenied());
        }
    }

    public function testTheTwoAttestationsAreSentOnlyAsJsonTrue(): void
    {
        $http = self::recorded();
        $input = $this->input();
        $input['versionAttested'] = true;
        $input['sensitiveExpressAttested'] = true;
        $this->client($http)->manualConsents()->record($input);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertTrue($body['versionAttested']);
        $this->assertTrue($body['sensitiveExpressAttested']);

        $http = self::recorded();
        $input = $this->input();
        $input['versionAttested'] = false;
        $this->client($http)->manualConsents()->record($input);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertArrayNotHasKey('versionAttested', $body, 'false is the server default, never sent');
        $this->assertArrayNotHasKey('sensitiveExpressAttested', $body);
    }

    public function testALooseAttestationOrAnUnknownMemberIsRefusedBeforeTheCall(): void
    {
        $loose = [
            ['versionAttested' => 'true'],
            ['sensitiveExpressAttested' => 1],
            ['versionAttest' => true],
            ['idempotencyKey' => 'in-the-body'],
        ];
        foreach ($loose as $extra) {
            $http = self::recorded();
            try {
                /** @phpstan-ignore argument.type (the point is a shape the type forbids) */
                $this->client($http)->manualConsents()->record($this->input() + $extra);
                $this->fail('expected AgreelyConfigError for ' . json_encode($extra));
            } catch (AgreelyConfigError) {
                $this->assertCount(0, $http->calls);
            }
        }
    }

    public function testAMissingVersionAttestationCarriesItsReasonAndField(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(422, ['error' => [
            'code' => 'invalid_request', 'message' => 'Signed before publication.', 'field' => 'versionAttested',
            'reason' => 'version_attestation_required',
        ]])]);
        try {
            $this->client($http)->manualConsents()->record($this->input());
            $this->fail('expected AgreelyValidationError');
        } catch (AgreelyValidationError $e) {
            $this->assertSame(ErrorReason::VERSION_ATTESTATION_REQUIRED, $e->reason);
            $this->assertSame('versionAttested', $e->field);
        }
    }

    public function testRevokeWhileAPasskeyConsentHoldsTheGateIsAConflictByReason(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(409, ['error' => [
            'code' => 'conflict', 'message' => 'A consent the person signed holds the purpose.', 'reason' => 'citizen_consent_at_gate',
        ]])]);
        try {
            $this->client($http)->manualConsents()->revoke(self::REF);
            $this->fail('expected AgreelyConflictError');
        } catch (AgreelyConflictError $e) {
            $this->assertTrue($e->isStateConflict());
            $this->assertSame(ErrorReason::CITIZEN_CONSENT_AT_GATE, $e->reason);
        }
    }

    public function testEraseReportsWhatTheGateDoesNow(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['consentRef' => self::REF, 'erased' => true, 'alreadyErased' => false, 'gate' => 'superseded']),
        ]);
        $r = $this->client($http)->manualConsents()->erase(self::REF);
        $this->assertSame(ManualConsentRevocation::GATE_SUPERSEDED, $r->gate);
        $this->assertFalse($r->gateDenied());
    }

    public function testCreateConsentSheetPrintsTheSheetAndReturnsTheClaimOnce(): void
    {
        $pdf = "%PDF-1.7\nblank sheet";
        $http = new MockHttpClient([MockHttpClient::json(201, [
            'signatureSheet' => [
                'documentVersionId' => 'e4d41dc3-c8a5-44b1-96c5-be740c45cc6d', 'locale' => 'en',
                'contentType' => 'application/pdf', 'filename' => 'agreely-formulaire-consentement-3.pdf',
                'pdf' => base64_encode($pdf),
            ],
            'printedReference' => 'ABCD-EFGH',
            'claim' => ['claimUrl' => 'https://my.agreely.ca/claim/tok', 'token' => 'tok', 'expiresAt' => '2026-11-08T00:00:00Z'],
        ])]);
        $sheet = $this->client($http)->manualConsents()->createConsentSheet('STORE/0042', [
            'documentVersionId' => 'e4d41dc3-c8a5-44b1-96c5-be740c45cc6d',
            'locale' => 'en',
        ], ['idempotencyKey' => 'sheet-0042-1']);
        $call = $http->calls[0];
        $this->assertSame('/v1/customers/STORE%2F0042/consent-sheets', parse_url($call->url, PHP_URL_PATH));
        $this->assertSame(['documentVersionId' => 'e4d41dc3-c8a5-44b1-96c5-be740c45cc6d', 'locale' => 'en'], $call->body);
        $this->assertSame('sheet-0042-1', $call->header('Idempotency-Key'));
        $this->assertInstanceOf(ConsentSheet::class, $sheet);
        $this->assertSame('ABCD-EFGH', $sheet->printedReference);
        $this->assertSame($pdf, $sheet->signatureSheet->bytes());
        $this->assertSame('en', $sheet->signatureSheet->locale);
        $this->assertSame('tok', $sheet->claim->token);
    }

    public function testCreateConsentSheetDefaultsToFrenchAndARenderBudget(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, ['signatureSheet' => [], 'printedReference' => 'X', 'claim' => []])]);
        $this->client($http)->manualConsents()->createConsentSheet('c-1', ['documentVersionId' => 'v']);
        $this->assertSame(['documentVersionId' => 'v', 'locale' => 'fr'], $http->calls[0]->body);
        $this->assertStringStartsWith('idem_', (string) $http->calls[0]->header('Idempotency-Key'));
        $this->assertGreaterThan(14_000, $http->calls[0]->timeoutMs, 'the server renders the PDF');
    }

    public function testCreateConsentSheetRefusesBeforeTheCall(): void
    {
        $bad = [
            ['c-1', []],
            ['c-1', ['documentVersionId' => 'v', 'locale' => 'de']],
            ['c-1', ['documentVersionId' => 'v', 'reference' => 'ABCD-EFGH']],
            [' ', ['documentVersionId' => 'v']],
        ];
        foreach ($bad as [$ref, $input]) {
            $http = new MockHttpClient([MockHttpClient::json(201, [])]);
            try {
                /** @phpstan-ignore argument.type (the point is a shape the type forbids) */
                $this->client($http)->manualConsents()->createConsentSheet($ref, $input);
                $this->fail('expected AgreelyConfigError for ' . json_encode($input));
            } catch (AgreelyConfigError) {
                $this->assertCount(0, $http->calls);
            }
        }
    }

    public function testASecondSheetUnderTheSameKeyIsALatchNotAReplay(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(409, ['error' => [
            'code' => 'already_minted', 'message' => 'Already produced.', 'reason' => 'already_minted',
        ]])]);
        try {
            $this->client($http)->manualConsents()->createConsentSheet('c-1', ['documentVersionId' => 'v'], ['idempotencyKey' => 'k-1']);
            $this->fail('expected AgreelyConflictError');
        } catch (AgreelyConflictError $e) {
            $this->assertSame(ErrorCode::ALREADY_MINTED, $e->code);
            $this->assertSame(ErrorReason::ALREADY_MINTED, $e->reason);
            $this->assertFalse($e->isRetryable());
        }
    }
}
