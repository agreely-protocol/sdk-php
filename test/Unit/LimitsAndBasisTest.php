<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\BatchCheckItem;
use Agreely\Sdk\Types\CheckBasis;
use Agreely\Sdk\Types\CheckStatus;
use PHPUnit\Framework\TestCase;

/**
 * The documented SERVER LIMITS (the 500-item batch cap) and the documented WIRE
 * FIELDS the SDK previously dropped (`basis`, and the necessity / sensitive statuses).
 *
 * The cap assertions matter because the server's 422 decides NOTHING: an over-cap
 * batch spends a request against the 120/minute allowance and comes back with no
 * decisions at all, which a fail-closed caller must treat as an all-deny. Refusing
 * client-side is strictly better, and it is the ONLY place the caller learns that
 * checkFields multiplies refs by fields.
 */
final class LimitsAndBasisTest extends TestCase
{
    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely([
            'apiKey'     => 'agr_live_test',
            'baseUrl'    => 'https://api.test',
            'httpClient' => $http,
        ]);
    }

    /** @return list<BatchCheckItem> */
    private static function items(int $count): array
    {
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = new BatchCheckItem("c{$i}", 'Email', 'Marketing');
        }

        return $items;
    }

    public function testCheckBatchAcceptsExactlyTheCap(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['decisions' => []])]);
        $this->client($http)->checkBatch(self::items(Agreely::BATCH_CAP));
        $this->assertCount(1, $http->calls, 'A batch AT the cap must still be sent.');
    }

    public function testCheckBatchRefusesOverTheCapWithoutTouchingTheWire(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['decisions' => []])]);
        try {
            $this->client($http)->checkBatch(self::items(Agreely::BATCH_CAP + 1));
            $this->fail('checkBatch must refuse an over-cap batch.');
        } catch (AgreelyConfigError $error) {
            $this->assertStringContainsString('501 items', $error->getMessage());
            $this->assertStringContainsString('cap of 500', $error->getMessage());
        }
        $this->assertSame([], $http->calls, 'An over-cap batch must never reach the wire.');
    }

    /**
     * The cartesian-product trap: 100 rows x 6 fields is 600 cells, over the cap, on a
     * perfectly ordinary listing page. The error must name BOTH multiplicands and the
     * safe page size, because "500" alone does not tell the caller what to change.
     */
    public function testCheckFieldsRefusesAnOverCapCartesianProduct(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['decisions' => []])]);
        $refs = [];
        for ($i = 0; $i < 100; $i++) {
            $refs[] = "cust-{$i}";
        }
        $fields = [
            ['category' => 'Email', 'purpose' => 'Marketing'],
            ['category' => 'Phone', 'purpose' => 'Billing'],
            ['category' => 'Address', 'purpose' => 'Delivery'],
            ['category' => 'Health', 'purpose' => 'Claims'],
            ['category' => 'Finance', 'purpose' => 'Pricing'],
            ['category' => 'Location', 'purpose' => 'Telematics'],
        ];

        try {
            $this->client($http)->checkFields($refs, $fields);
            $this->fail('checkFields must refuse an over-cap product.');
        } catch (AgreelyConfigError $error) {
            $this->assertStringContainsString('100 customerRefs x 6 fields = 600 cells', $error->getMessage());
            $this->assertStringContainsString('at most 83 per call', $error->getMessage());
        }
        $this->assertSame([], $http->calls, 'An over-cap product must never reach the wire.');
    }

    public function testCheckFieldsAcceptsAProductExactlyAtTheCap(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['decisions' => []])]);
        $refs = [];
        for ($i = 0; $i < 250; $i++) {
            $refs[] = "cust-{$i}";
        }
        $fields = [
            ['category' => 'Email', 'purpose' => 'Marketing'],
            ['category' => 'Phone', 'purpose' => 'Billing'],
        ];
        $this->client($http)->checkFields($refs, $fields);
        $this->assertCount(1, $http->calls);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $sent = $body['items'];
        $this->assertIsArray($sent);
        $this->assertCount(500, $sent);
    }

    /**
     * A DECLARED-NECESSITY allow: allow / status "necessity" / basis, and NO consentRef
     * and NO assurance (there is no signed proof). Captured verbatim from the live local
     * API, which openapi.yaml documents and the SDK used to silently drop.
     */
    public function testCheckDetailedSurfacesTheNecessityBasis(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'decision'  => 'allow',
                'status'    => 'necessity',
                'basis'     => 'necessary_for_service',
                'checkedAt' => '2026-08-22T17:04:57Z',
            ]),
        ]);
        $result = $this->client($http)->checkDetailed('SDK-AUDIT-NOBODY-1', 'Coordonnees', 'Gestion du dossier client');

        $this->assertTrue($result->isAllow());
        $this->assertSame(CheckStatus::NECESSITY, $result->status);
        $this->assertSame(CheckBasis::NECESSARY_FOR_SERVICE, $result->basis);
        $this->assertTrue($result->isNecessity(), 'A necessity allow must be distinguishable from a consented allow.');
        $this->assertNull($result->consentRef, 'A necessity allow has no signed consent behind it.');
        $this->assertNull($result->assurance, 'A necessity allow has no assurance tier.');
    }

    public function testConsentBackedAllowCarriesNoBasis(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'decision'   => 'allow',
                'status'     => 'active',
                'consentRef' => '0xce324c62',
                'assurance'  => 'company_attested',
                'checkedAt'  => '2026-08-22T17:05:55Z',
            ]),
        ]);
        $result = $this->client($http)->checkDetailed('c1', 'Coordonnees', 'Communications marketing');

        $this->assertNull($result->basis, 'Only a necessity allow carries a basis.');
        $this->assertFalse($result->isNecessity());
        $this->assertSame('company_attested', $result->assurance);
    }

    public function testBatchDecisionSurfacesTheNecessityBasis(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'decisions' => [[
                    'customerRef' => 'SDK-AUDIT-0',
                    'category'    => 'Coordonnees',
                    'purpose'     => 'Gestion du dossier client',
                    'decision'    => 'allow',
                    'status'      => 'necessity',
                    'basis'       => 'necessary_for_service',
                    'checkedAt'   => '2026-08-22T17:05:20Z',
                ]],
            ]),
        ]);
        $decisions = $this->client($http)->checkBatch([new BatchCheckItem('SDK-AUDIT-0', 'Coordonnees', 'Gestion du dossier client')]);

        $this->assertCount(1, $decisions);
        $this->assertSame(CheckBasis::NECESSARY_FOR_SERVICE, $decisions[0]->basis);
        $this->assertTrue($decisions[0]->isNecessity());
        $this->assertNull($decisions[0]->consentRef);
    }

    /**
     * "sensitive_requires_consent" is no longer emitted (2026-09-28): a sensitive cell on
     * a non-consent basis with no record answers allow / necessity + basis like any other.
     */
    public function testSensitiveCellOnANonConsentBasisAllowsOnNecessity(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'decision'  => 'allow',
                'status'    => 'necessity',
                'basis'     => 'legal_obligation',
                'checkedAt' => '2026-09-29T17:04:57Z',
            ]),
        ]);
        $result = $this->client($http)->checkDetailed('c1', 'Renseignements medicaux', 'Obligation fiscale');

        $this->assertTrue($result->isAllow());
        $this->assertTrue($result->isNecessity());
        $this->assertSame(CheckBasis::LEGAL_OBLIGATION, $result->basis);
        $this->assertNull($result->consentRef);
        $this->assertNull($result->assurance);
        $this->assertNull($result->tier);
        $this->assertNotContains('sensitive_requires_consent', CheckStatus::ALL);
    }

    /**
     * The status vocabulary must match openapi.yaml exactly: nine values, and the two
     * that allow are exactly "active" and "necessity".
     */
    public function testStatusVocabularyMatchesTheSpec(): void
    {
        $this->assertSame([
            'active',
            'necessity',
            'none',
            'revoked',
            'expired',
            'erased',
            'relationship_ended',
            'requires_depersonalization',
            'basis_not_in_regime',
        ], CheckStatus::ALL);
        $this->assertSame(['active', 'necessity'], CheckStatus::ALLOWING);
        $this->assertSame([
            'contract',
            'necessary_for_service',
            'security_fraud',
            'legal_obligation',
            'professional_contact',
            'attributions',
            'programme',
            'entente_collecte',
            'compatible_use',
            'manifest_benefit',
            'law_application',
            'public_character',
        ], CheckBasis::ALL);
        $this->assertSame(CheckBasis::ALL, array_merge(CheckBasis::PRIVATE, CheckBasis::PUBLIC));
    }
}
