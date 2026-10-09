<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Errors\AgreelyUnavailableError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Errors\ErrorCode;
use Agreely\Sdk\Http\RawResponse;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\Regime;
use Agreely\Sdk\Types\Scope;
use PHPUnit\Framework\TestCase;

final class ConsentDocumentsTest extends TestCase
{
    private const VERSION = 'e4d41dc3-c8a5-44b1-96c5-be740c45cc6d';

    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http]);
    }

    /** @return array<string,mixed> */
    private static function summary(): array
    {
        return [
            'code' => 'conditions-marketing',
            'documentVersionId' => self::VERSION,
            'version' => '3',
            'name' => 'Conditions marketing',
            'nameEn' => null,
            'effectiveDate' => '2026-09-01',
            'publishedAt' => '2026-08-26T12:57:08Z',
            'items' => [[
                'id' => 'cell-1', 'category' => 'Photo', 'categoryEn' => 'Photo', 'purpose' => 'Publication',
                'purposeEn' => null, 'sensitive' => true,
            ]],
        ];
    }

    public function testListMapsEveryPublishedDocument(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['documents' => [self::summary()]])]);
        $documents = $this->client($http)->consentDocuments()->list();
        $this->assertCount(1, $documents);
        $this->assertSame('conditions-marketing', $documents[0]->code);
        $this->assertSame(self::VERSION, $documents[0]->documentVersionId);
        $this->assertNull($documents[0]->nameEn);
        $this->assertTrue($documents[0]->items[0]->sensitive);
        $this->assertNull($documents[0]->items[0]->purposeEn);
        $this->assertSame('GET', $http->calls[0]->method);
        $this->assertSame('/v1/consent-documents', $http->calls[0]->path());
    }

    public function testGetReadsTheDetailByItsStableCode(): void
    {
        $detail = self::summary() + [
            'disclosure' => [
                'purpose' => ['fr' => 'Publier les photos', 'en' => null],
                'withdrawal' => ['fr' => 'Écrire au responsable', 'en' => 'Write to the officer'],
            ],
            'responsable' => ['name' => 'Julie Tremblay', 'contact' => 'vie-privee@example.com'],
            'automatedDecision' => ['declared' => false, 'note' => ['fr' => null, 'en' => null]],
            'profiling' => ['declared' => true, 'note' => ['fr' => 'Segmentation', 'en' => null]],
            'integrity' => ['ipfsCid' => 'bafy123', 'anchorStatus' => 'anchored'],
        ];
        $http = new MockHttpClient([MockHttpClient::json(200, ['document' => $detail])]);
        $doc = $this->client($http)->consentDocuments()->get('Conditions Marketing');
        $this->assertSame('/v1/consent-documents/Conditions%20Marketing', parse_url($http->calls[0]->url, PHP_URL_PATH));
        $this->assertSame(self::VERSION, $doc->documentVersionId);
        $this->assertSame('Publier les photos', $doc->disclosure->purpose->text('fr'));
        $this->assertNull($doc->disclosure->purpose->text('en'), 'never falls back to the other language');
        $this->assertSame('Write to the officer', $doc->disclosure->withdrawal->en);
        $this->assertNull($doc->disclosure->rights->fr, 'an absent section reads as unwritten');
        $this->assertSame('Julie Tremblay', $doc->responsable['name']);
        $this->assertTrue($doc->profiling->declared);
        $this->assertFalse($doc->automatedDecision->declared);
        $this->assertSame('bafy123', $doc->integrity['ipfsCid']);
        $this->assertCount(1, $doc->items);
    }

    public function testInformationPdfReturnsTheBytesForTheLocaleAsked(): void
    {
        $bytes = "%PDF-1.7\n\x00\xff binary";
        $http = new MockHttpClient([new RawResponse(200, $bytes, [
            'content-type' => 'application/pdf',
            'content-disposition' => 'attachment; filename="agreely-document-information-3-en.pdf"',
        ])]);
        $document = $this->client($http)->consentDocuments()->getInformationPdf(self::VERSION, ['locale' => 'en']);
        $this->assertSame($bytes, $document->pdf);
        $this->assertSame('agreely-document-information-3-en.pdf', $document->filename);
        $this->assertSame('application/pdf', $document->contentType);
        $call = $http->calls[0];
        $this->assertSame('GET', $call->method);
        $this->assertSame('/v1/consent-documents/versions/' . self::VERSION . '/pdf', $call->path());
        $this->assertSame('locale=en', $call->query());
        $this->assertStringContainsString('application/pdf', (string) $call->header('Accept'));
    }

    public function testInformationPdfDefaultsToFrench(): void
    {
        $http = new MockHttpClient([new RawResponse(200, '%PDF-1.4', [])]);
        $document = $this->client($http)->consentDocuments()->getInformationPdf(self::VERSION);
        $this->assertSame('locale=fr', $http->calls[0]->query());
        $this->assertNull($document->filename);
        $this->assertGreaterThan(14_000, $http->calls[0]->timeoutMs, 'the render budget, not the 800 ms check budget');
        $this->assertLessThanOrEqual(15_000, $http->calls[0]->timeoutMs);
    }

    public function testInformationPdfMapsAJsonRefusalToItsTypedError(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(404, ['error' => ['code' => 'not_found', 'message' => 'none']])]);
        $this->expectException(AgreelyNotFoundError::class);
        $this->client($http)->consentDocuments()->getInformationPdf(self::VERSION);
    }

    public function testAnEnglishCopyWithMissingTextIsItsOwnCode(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(422, [
            'error' => ['code' => 'english_text_missing', 'message' => 'no English', 'field' => 'locale'],
        ])]);
        try {
            $this->client($http)->consentDocuments()->getInformationPdf(self::VERSION, ['locale' => 'en']);
            $this->fail('expected AgreelyValidationError');
        } catch (AgreelyValidationError $e) {
            $this->assertSame(ErrorCode::ENGLISH_TEXT_MISSING, $e->code);
            $this->assertSame('locale', $e->field);
        }
    }

    public function testA200ThatIsNotAPdfIsNeverHandedBackAsOne(): void
    {
        $http = new MockHttpClient([new RawResponse(200, '<html>login</html>', ['content-type' => 'text/html'])]);
        $this->expectException(AgreelyUnavailableError::class);
        $this->client($http)->consentDocuments()->getInformationPdf(self::VERSION);
    }

    public function testInformationPdfRefusesBeforeTheCall(): void
    {
        $bad = [
            [self::VERSION, ['locale' => 'de']],
            [self::VERSION, ['timeout' => 0]],
            [self::VERSION, ['lang' => 'fr']],
            ['not-a-uuid', []],
        ];
        foreach ($bad as [$id, $options]) {
            $http = new MockHttpClient([new RawResponse(200, '%PDF-1.4', [])]);
            try {
                $this->client($http)->consentDocuments()->getInformationPdf($id, $options);
                $this->fail('expected AgreelyConfigError for ' . json_encode($options));
            } catch (AgreelyConfigError) {
                $this->assertCount(0, $http->calls);
            }
        }
    }

    public function testInformationPdfTakesAnExplicitTimeoutAndKeepsALargerClientOne(): void
    {
        $http = new MockHttpClient([new RawResponse(200, '%PDF-1.4', [])]);
        $this->client($http)->consentDocuments()->getInformationPdf(self::VERSION, ['timeout' => 3000]);
        $this->assertGreaterThan(2_000, $http->calls[0]->timeoutMs);
        $this->assertLessThanOrEqual(3_000, $http->calls[0]->timeoutMs);

        $http = new MockHttpClient([new RawResponse(200, '%PDF-1.4', [])]);
        $client = new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http, 'timeout' => 30_000]);
        $client->consentDocuments()->getInformationPdf(self::VERSION);
        $this->assertGreaterThan(15_000, $http->calls[0]->timeoutMs);
    }

    public function testCatalogForDocumentNarrowsToOneDocumentAndNamesItsVersion(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'regime' => ['sector' => 'public', 'statute' => 'A-2.1'],
            'document' => ['code' => 'conditions-marketing', 'documentVersionId' => self::VERSION],
            'catalog' => [[
                'id' => 'cell-1', 'category' => 'Photo', 'purpose' => 'Publication', 'description' => null,
                'legalBasis' => 'consent', 'sensitive' => false,
            ]],
        ])]);
        $catalog = $this->client($http)->catalog()->forDocument('conditions-marketing');
        $this->assertSame('documentCode=conditions-marketing', $http->calls[0]->query());
        $this->assertSame('/v1/catalog', $http->calls[0]->path());
        $this->assertSame(self::VERSION, $catalog->documentVersionId);
        $this->assertSame('conditions-marketing', $catalog->documentCode);
        $this->assertNotNull($catalog->regime);
        $this->assertSame(Regime::SECTOR_PUBLIC, $catalog->regime->sector);
        $this->assertSame('consent', $catalog->entries[0]->legalBasis);
        $this->assertFalse($catalog->entries[0]->sensitive);
    }

    public function testIdentityCarriesTheOrganisationItDiscovers(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'scopes' => ['check', 'withdraw', 'holds'],
            'company' => [
                'name' => 'Ville exemple', 'sector' => 'public', 'statute' => 'A-2.1',
                'publicPolicyUrl' => 'https://app.agreely.ca/p/ville-exemple/privacy',
            ],
        ])]);
        $identity = $this->client($http)->identity();
        $this->assertNotNull($identity->company);
        $this->assertSame('Ville exemple', $identity->company->name);
        $this->assertSame('A-2.1', $identity->company->regime()->statute);
        $this->assertSame('https://app.agreely.ca/p/ville-exemple/privacy', $identity->company->publicPolicyUrl);
        $this->assertTrue($identity->hasScope(Scope::WITHDRAW));
        $this->assertFalse($identity->hasScope(Scope::REGISTRY));
    }

    public function testIdentityWithoutACompanyAndANullPolicyUrl(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['scopes' => ['check']])]);
        $this->assertNull($this->client($http)->identity()->company);

        $http = new MockHttpClient([MockHttpClient::json(200, [
            'scopes' => ['check'],
            'company' => ['name' => 'X', 'sector' => 'private', 'statute' => 'P-39.1', 'publicPolicyUrl' => null],
        ])]);
        $company = $this->client($http)->identity()->company;
        $this->assertNotNull($company);
        $this->assertNull($company->publicPolicyUrl);
    }

    public function testTheScopeVocabularyNamesWithdrawAndHolds(): void
    {
        $this->assertContains('withdraw', Scope::ALL);
        $this->assertContains('holds', Scope::ALL);
        $this->assertCount(10, Scope::ALL);
    }
}
