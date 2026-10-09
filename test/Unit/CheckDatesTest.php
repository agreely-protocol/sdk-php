<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Test\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class CheckDatesTest extends TestCase
{
    private const REF = '0x0fc2fb50059b4ca665cd931022beb058139acb702efb4a111519ac0e92f5337c';

    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http]);
    }

    public function testAnActiveConsentCarriesItsEndAndNoWithdrawal(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'decision' => 'allow', 'status' => 'active', 'consentRef' => self::REF, 'assurance' => 'company_documented',
            'tier' => 'verbal', 'validUntil' => '2027-10-09T03:59:59Z', 'checkedAt' => '2026-10-09T12:00:00Z',
        ])]);
        $d = $this->client($http)->checkDetailed('c', 'Photo', 'Publication');
        $this->assertSame('2027-10-09T03:59:59Z', $d->validUntil);
        $this->assertNull($d->revokedAt);
    }

    public function testARevokedConsentCarriesBothDates(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'decision' => 'deny', 'status' => 'revoked', 'consentRef' => self::REF, 'assurance' => 'company_attested',
            'tier' => 'manual', 'validUntil' => '2028-10-09T03:59:59Z', 'revokedAt' => '2026-10-08T20:08:52Z',
            'checkedAt' => '2026-10-08T20:13:48Z',
        ])]);
        $d = $this->client($http)->checkDetailed('c', 'Photo', 'Publication');
        $this->assertFalse($d->isAllow());
        $this->assertSame('2028-10-09T03:59:59Z', $d->validUntil);
        $this->assertSame('2026-10-08T20:08:52Z', $d->revokedAt);
    }

    public function testANecessityAnswerHasNoDatesBecauseTheyAreAbsentFromTheWire(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'decision' => 'allow', 'status' => 'necessity', 'basis' => 'necessary_for_service', 'checkedAt' => 't',
        ])]);
        $d = $this->client($http)->checkDetailed('c', 'Adresse', 'Livraison');
        $this->assertNull($d->validUntil);
        $this->assertNull($d->revokedAt);
    }

    public function testALegacyConsentWithNoEndReadsNull(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'decision' => 'allow', 'status' => 'active', 'consentRef' => self::REF, 'assurance' => 'citizen_signed',
            'tier' => 'full', 'validUntil' => null, 'checkedAt' => 't',
        ])]);
        $this->assertNull($this->client($http)->checkDetailed('c', 'a', 'b')->validUntil);
    }

    public function testTheBatchCarriesTheSameDatesPerDecision(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['decisions' => [
            [
                'customerRef' => 'c1', 'category' => 'Photo', 'purpose' => 'Publication', 'decision' => 'deny',
                'status' => 'revoked', 'consentRef' => self::REF, 'assurance' => 'company_attested', 'tier' => 'manual',
                'validUntil' => '2028-10-09T03:59:59Z', 'revokedAt' => '2026-10-08T20:08:52Z', 'checkedAt' => 't',
            ],
            [
                'customerRef' => 'c2', 'category' => 'Photo', 'purpose' => 'Publication', 'decision' => 'deny',
                'status' => 'none', 'checkedAt' => 't',
            ],
        ]])]);
        $decisions = $this->client($http)->checkBatch([
            ['customerRef' => 'c1', 'category' => 'Photo', 'purpose' => 'Publication'],
            ['customerRef' => 'c2', 'category' => 'Photo', 'purpose' => 'Publication'],
        ]);
        $this->assertSame('2028-10-09T03:59:59Z', $decisions[0]->validUntil);
        $this->assertSame('2026-10-08T20:08:52Z', $decisions[0]->revokedAt);
        $this->assertNull($decisions[1]->validUntil);
        $this->assertNull($decisions[1]->revokedAt);
    }
}
