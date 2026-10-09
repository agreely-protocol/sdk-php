<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\Assurance;
use Agreely\Sdk\Types\CheckResult;
use Agreely\Sdk\Types\ConsentTier;
use PHPUnit\Framework\TestCase;

final class CheckTest extends TestCase
{
    /** @param array<string,mixed> $extra */
    private function client(MockHttpClient $http, array $extra = []): Agreely
    {
        return new Agreely(array_merge([
            'apiKey' => 'agr_live_test',
            'baseUrl' => 'https://api.test',
            'httpClient' => $http,
        ], $extra));
    }

    public function testAllowIsTheOnlyTrue(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['decision' => 'allow', 'status' => 'active', 'consentRef' => '0xabc', 'checkedAt' => '2026-01-01T00:00:00Z']),
        ]);
        $this->assertTrue($this->client($http)->check('cust', 'Phone number', 'Billing'));
    }

    public function testDenyMapsToFalse(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['decision' => 'deny', 'status' => 'revoked', 'consentRef' => '0xabc', 'checkedAt' => '2026-01-01T00:00:00Z']),
        ]);
        $this->assertFalse($this->client($http)->check('cust', 'Phone number', 'Billing'));
    }

    public function testStatusNoneDenyHasNoConsentRef(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['decision' => 'deny', 'status' => 'none', 'checkedAt' => '2026-01-01T00:00:00Z']),
        ]);
        $d = $this->client($http)->checkDetailed('cust', 'Phone number', 'Billing');
        $this->assertInstanceOf(CheckResult::class, $d);
        $this->assertSame('deny', $d->decision);
        $this->assertSame('none', $d->status);
        $this->assertNull($d->consentRef);
        $this->assertFalse($d->degraded);
    }

    public function testCheckDetailedShapeForAllow(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['decision' => 'allow', 'status' => 'active', 'consentRef' => '0xdeadbeef', 'checkedAt' => '2026-06-27T10:00:00Z']),
        ]);
        $d = $this->client($http)->checkDetailed('cust', 'Phone number', 'Billing');
        $this->assertSame('allow', $d->decision);
        $this->assertSame('active', $d->status);
        $this->assertSame('0xdeadbeef', $d->consentRef);
        $this->assertSame('2026-06-27T10:00:00Z', $d->checkedAt);
        $this->assertFalse($d->degraded);
        $this->assertNull($d->mode);
    }

    public function testSendsRawCategoryAndPurposeOnTheWire(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['decision' => 'allow', 'status' => 'active', 'consentRef' => '0x1', 'checkedAt' => 'now']),
        ]);
        // Mixed casing + whitespace MUST go raw, the SDK never normalizes.
        $this->client($http)->check('cust_8812', '  Email   ADDRESS ', "Marketing\tOutreach");
        $call = $http->calls[0];
        $this->assertSame('POST', $call->method);
        $this->assertSame('/v1/check', $call->path());
        $this->assertNotNull($call->body);
        $this->assertSame('  Email   ADDRESS ', $call->body['category']);
        $this->assertSame("Marketing\tOutreach", $call->body['purpose']);
        $this->assertSame('cust_8812', $call->body['customerId']);
        $this->assertSame('Bearer agr_live_test', $call->header('Authorization'));
    }

    public function testNoAllowCacheEveryCheckHitsTheServer(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['decision' => 'allow', 'status' => 'active', 'consentRef' => '0x1', 'checkedAt' => 'now']),
            MockHttpClient::json(200, ['decision' => 'deny', 'status' => 'revoked', 'consentRef' => '0x1', 'checkedAt' => 'now']),
        ]);
        $client = $this->client($http);
        // First allow, then an out-of-band revoke lands -> the very next check denies.
        $this->assertTrue($client->check('cust', 'Email Address', 'Marketing'));
        $this->assertFalse($client->check('cust', 'Email Address', 'Marketing'));
        $this->assertCount(2, $http->calls); // two distinct authoritative calls, no cache
    }

    public function testEmptyApiKeyThrowsConfigError(): void
    {
        $this->expectException(AgreelyConfigError::class);
        new Agreely(['apiKey' => '  ']);
    }

    public function testCheckCarriesTheVerbalTierAndCompanyDocumentedAssurance(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'decision' => 'allow', 'status' => 'active', 'consentRef' => '0xabc',
                'assurance' => 'company_documented', 'tier' => 'verbal', 'checkedAt' => '2026-09-29T00:00:00Z',
            ]),
        ]);
        $r = $this->client($http)->checkDetailed('cust', 'Phone number', 'Billing');
        $this->assertSame(Assurance::COMPANY_DOCUMENTED, $r->assurance);
        $this->assertSame(ConsentTier::VERBAL, $r->tier);
        $this->assertTrue(ConsentTier::atLeast($r->tier, ConsentTier::VERBAL));
        $this->assertFalse(ConsentTier::atLeast($r->tier, ConsentTier::MANUAL));
    }

    public function testAWithdrawnInformedLineDeniesRevokedWithARefButNoAssuranceOrTier(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'decision' => 'deny', 'status' => 'revoked', 'consentRef' => '0xabc', 'checkedAt' => '2026-09-29T00:00:00Z',
            ]),
        ]);
        $r = $this->client($http)->checkDetailed('cust', 'Phone number', 'Billing');
        $this->assertSame('0xabc', $r->consentRef);
        $this->assertNull($r->assurance);
        $this->assertNull($r->tier);
    }

    public function testBatchDecisionCarriesTier(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['decisions' => [[
                'customerRef' => 'c1', 'category' => 'Email', 'purpose' => 'News', 'decision' => 'allow',
                'status' => 'active', 'consentRef' => '0xabc', 'assurance' => 'company_attested', 'tier' => 'manual',
                'checkedAt' => '2026-09-29T00:00:00Z',
            ]]]),
        ]);
        $d = $this->client($http)->checkBatch([['customerRef' => 'c1', 'category' => 'Email', 'purpose' => 'News']]);
        $this->assertSame(ConsentTier::MANUAL, $d[0]->tier);
    }

    public function testAnUnknownTierIsNeverAcceptable(): void
    {
        $this->assertFalse(ConsentTier::atLeast('platinum', ConsentTier::VERBAL));
        $this->assertFalse(ConsentTier::atLeast(null, ConsentTier::VERBAL));
        $this->assertTrue(ConsentTier::atLeast(ConsentTier::FULL, ConsentTier::MANUAL));
        $this->assertSame(['citizen_signed', 'company_attested', 'company_documented'], Assurance::ALL);
    }
}
