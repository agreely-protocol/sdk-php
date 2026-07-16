<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyAuthError;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\CancelledRequest;
use Agreely\Sdk\Types\CatalogEntry;
use Agreely\Sdk\Types\ConsentRequestPage;
use Agreely\Sdk\Types\Identity;
use Agreely\Sdk\Types\IssuedRequest;
use PHPUnit\Framework\TestCase;

final class ResourcesTest extends TestCase
{
    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http]);
    }

    public function testCreateAutoGeneratesAnIdempotencyKey(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(201, [
                'requestId' => '0x' . str_repeat('b', 64),
                'status' => 'pending',
                'deepLink' => 'https://link',
                'emailDelivered' => true,
                'items' => [['category' => 'Phone Number', 'purpose' => 'Account Security']],
                'document' => ['code' => 'terms', 'name' => 'Terms', 'version' => '1.0'],
            ]),
        ]);
        $r = $this->client($http)->consentRequests()->create([
            'customerId' => 'cust',
            'recipientEmail' => 'r@e.com',
            'consentDocumentId' => '6a1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b',
            'validUntil' => '2031-01-01',
        ]);
        $this->assertInstanceOf(IssuedRequest::class, $r);
        $this->assertSame('pending', $r->status);
        $this->assertCount(1, $r->items);
        $this->assertSame('Phone Number', $r->items[0]->category);
        $this->assertNotNull($r->document);
        $this->assertSame('terms', $r->document->code);
        $this->assertSame('1.0', $r->document->version);

        $key = $http->calls[0]->header('Idempotency-Key');
        $this->assertNotNull($key);
        $this->assertStringStartsWith('idem_', $key);
    }

    public function testCreateAutoKeyIsUniquePerCall(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(201, ['requestId' => '0x' . str_repeat('a', 64), 'status' => 'pending', 'deepLink' => 'x', 'emailDelivered' => true, 'items' => []]),
        ]);
        $client = $this->client($http);
        $input = ['customerId' => 'c', 'recipientEmail' => 'r@e.com', 'consentDocumentId' => '6a1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b', 'validUntil' => '2031-01-01'];
        $client->consentRequests()->create($input);
        $client->consentRequests()->create($input);
        $this->assertNotSame(
            $http->calls[0]->header('Idempotency-Key'),
            $http->calls[1]->header('Idempotency-Key'),
        );
    }

    public function testCreateHonoursAnOverriddenIdempotencyKey(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(201, ['requestId' => '0x' . str_repeat('a', 64), 'status' => 'pending', 'deepLink' => 'x', 'emailDelivered' => true, 'items' => []]),
        ]);
        $this->client($http)->consentRequests()->create(
            ['customerId' => 'c', 'recipientEmail' => 'r@e.com', 'consentDocumentId' => '6a1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b', 'validUntil' => '2031-01-01'],
            ['idempotencyKey' => 'order-4471'],
        );
        $this->assertSame('order-4471', $http->calls[0]->header('Idempotency-Key'));
    }

    public function testCreateSendsTheDocumentIdAndNeverAnItemsList(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(201, ['requestId' => '0x' . str_repeat('a', 64), 'status' => 'pending', 'deepLink' => 'x', 'emailDelivered' => true, 'items' => []]),
        ]);
        $this->client($http)->consentRequests()->create([
            'customerId' => 'c',
            'recipientEmail' => 'r@e.com',
            'consentDocumentId' => '6a1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b',
            'validUntil' => '2031-01-01',
        ]);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertSame('6a1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b', $body['consentDocumentId']);
        $this->assertArrayNotHasKey('items', $body);
        $this->assertArrayNotHasKey('documentCode', $body);
    }

    public function testCreateSendsDocumentCodeWhenGivenInsteadOfTheId(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(201, ['requestId' => '0x' . str_repeat('a', 64), 'status' => 'pending', 'deepLink' => 'x', 'emailDelivered' => true, 'items' => []]),
        ]);
        $this->client($http)->consentRequests()->create([
            'customerId' => 'c',
            'recipientEmail' => 'r@e.com',
            'documentCode' => 'conditions-marketing',
            'validUntil' => '2031-01-01',
        ]);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertSame('conditions-marketing', $body['documentCode']);
        $this->assertArrayNotHasKey('consentDocumentId', $body);
    }

    public function testCreateWithoutADocumentThrowsConfigErrorAndSendsNothing(): void
    {
        $http = new MockHttpClient([]);
        try {
            $this->client($http)->consentRequests()->create([
                'customerId' => 'c', 'recipientEmail' => 'r@e.com', 'validUntil' => '2031-01-01',
            ]);
            $this->fail('A document-less create must throw.');
        } catch (\Agreely\Sdk\Errors\AgreelyConfigError $e) {
            $this->assertStringContainsString('consentDocumentId', $e->getMessage());
        }
        $this->assertCount(0, $http->calls, 'Nothing was sent.');
    }

    public function testCreateWithBothIdAndCodeThrowsConfigError(): void
    {
        $http = new MockHttpClient([]);
        $this->expectException(\Agreely\Sdk\Errors\AgreelyConfigError::class);
        $this->client($http)->consentRequests()->create([
            'customerId' => 'c', 'recipientEmail' => 'r@e.com', 'validUntil' => '2031-01-01',
            'consentDocumentId' => '6a1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b', 'documentCode' => 'terms',
        ]);
    }

    public function testListMapsRequestsToItemsAndCursor(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'requests' => [
                    [
                        'requestId' => '0x' . str_repeat('c', 64),
                        'status' => 'pending',
                        'validUntil' => '2031-01-01',
                        'expiresAt' => '2026-07-01',
                        'createdAt' => '2026-06-01',
                        'settledAt' => null,
                        'customerId' => 'cust_9',
                        'documentCode' => 'infolettre',
                        'items' => [],
                    ],
                ],
                'nextCursor' => '0x' . str_repeat('d', 64),
            ]),
        ]);
        $page = $this->client($http)->consentRequests()->list([
            'customerId' => 'cust_9',
            'status' => 'pending',
            'limit' => 25,
            'cursor' => '0xprev',
        ]);
        $this->assertInstanceOf(ConsentRequestPage::class, $page);
        $this->assertCount(1, $page->items);
        $this->assertSame('0x' . str_repeat('d', 64), $page->nextCursor);
        $this->assertNull($page->items[0]->settledAt);
        // The new metadata fields are surfaced on the record.
        $this->assertSame('cust_9', $page->items[0]->customerId);
        $this->assertSame('infolettre', $page->items[0]->documentCode);
        // All filters travel on the wire.
        $query = $http->calls[0]->query();
        $this->assertStringContainsString('customerId=cust_9', $query);
        $this->assertStringContainsString('status=pending', $query);
        $this->assertStringContainsString('limit=25', $query);
        $this->assertStringContainsString('cursor=', $query);
    }

    public function testHasPendingReturnsTrueWhenAPendingRequestExists(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'requests' => [$this->pendingRec('0xa', 'cust_1', 'conditions-marketing')],
                'nextCursor' => null,
            ]),
        ]);
        $has = $this->client($http)->consentRequests()->hasPending('cust_1');
        $this->assertTrue($has);
        // It queries status:pending scoped to the customer.
        $query = $http->calls[0]->query();
        $this->assertStringContainsString('customerId=cust_1', $query);
        $this->assertStringContainsString('status=pending', $query);
    }

    public function testHasPendingReturnsFalseWhenNoPendingRequestExists(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['requests' => [], 'nextCursor' => null]),
        ]);
        $this->assertFalse($this->client($http)->consentRequests()->hasPending('cust_1'));
    }

    public function testHasPendingNarrowsToADocumentCode(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'requests' => [
                    $this->pendingRec('0xa', 'cust_1', 'conditions-marketing'),
                    $this->pendingRec('0xb', 'cust_1', 'infolettre'),
                ],
                'nextCursor' => null,
            ]),
        ]);
        $this->assertTrue($this->client($http)->consentRequests()->hasPending('cust_1', 'infolettre'));
    }

    public function testHasPendingIsFalseForADifferentDocumentCode(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'requests' => [$this->pendingRec('0xa', 'cust_1', 'conditions-marketing')],
                'nextCursor' => null,
            ]),
        ]);
        $this->assertFalse($this->client($http)->consentRequests()->hasPending('cust_1', 'infolettre'));
    }

    public function testHasPendingThrowsOnBlankCustomerIdBeforeAnyWireCall(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['requests' => [], 'nextCursor' => null]),
        ]);
        $this->expectException(AgreelyConfigError::class);
        try {
            $this->client($http)->consentRequests()->hasPending('   ');
        } finally {
            $this->assertCount(0, $http->calls);
        }
    }

    /** @return array<string,mixed> */
    private function pendingRec(string $id, string $customerId, string $documentCode): array
    {
        return [
            'requestId' => $id,
            'status' => 'pending',
            'validUntil' => '2031-01-01',
            'expiresAt' => '2026-07-01',
            'createdAt' => '2026-06-01',
            'settledAt' => null,
            'customerId' => $customerId,
            'documentCode' => $documentCode,
            'items' => [],
        ];
    }

    public function testGetUsesTheProtocolRequestIdInThePath(): void
    {
        $rid = '0x' . str_repeat('e', 64);
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'requestId' => $rid,
                'status' => 'approved',
                'validUntil' => '2031-01-01',
                'expiresAt' => '2026-07-01',
                'createdAt' => '2026-06-01',
                'settledAt' => '2026-06-02',
                'items' => [],
            ]),
        ]);
        $rec = $this->client($http)->consentRequests()->get($rid);
        $this->assertSame($rid, $rec->requestId);
        $this->assertSame('/v1/consent-requests/' . $rid, $http->calls[0]->path());
    }

    public function testIdentityReturnsServerVerifiedScopes(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['scopes' => ['check', 'issue']]),
        ]);
        $identity = $this->client($http)->identity();
        $this->assertInstanceOf(Identity::class, $identity);
        $this->assertSame(['check', 'issue'], $identity->scopes);
        $this->assertSame('https://api.test', $identity->baseUrl);
        $this->assertSame('GET', $http->calls[0]->method);
        $this->assertSame('/v1/whoami', $http->calls[0]->path());
    }

    public function testIdentitySurfacesA401AsAuthError(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(401, ['error' => ['code' => 'unauthorized', 'message' => 'no']]),
        ]);
        $this->expectException(AgreelyAuthError::class);
        $this->client($http)->identity();
    }

    public function testCancelReturnsTheCancelOutcome(): void
    {
        $rid = '0x' . str_repeat('a', 64);
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['requestId' => $rid, 'status' => 'revoked_before_action', 'cancelled' => true]),
        ]);
        $out = $this->client($http)->consentRequests()->cancel($rid);
        $this->assertInstanceOf(CancelledRequest::class, $out);
        $this->assertSame($rid, $out->requestId);
        $this->assertSame('revoked_before_action', $out->status);
        $this->assertTrue($out->cancelled);
        $this->assertSame('POST', $http->calls[0]->method);
        $this->assertSame('/v1/consent-requests/' . $rid . '/cancel', $http->calls[0]->path());
    }

    public function testCancelIsIdempotentOnTerminalRequest(): void
    {
        $rid = '0x' . str_repeat('a', 64);
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['requestId' => $rid, 'status' => 'approved', 'cancelled' => false]),
        ]);
        $out = $this->client($http)->consentRequests()->cancel($rid);
        $this->assertFalse($out->cancelled);
        $this->assertSame('approved', $out->status);
    }

    public function testCancelMapsA404ToNotFound(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(404, ['error' => ['code' => 'not_found', 'message' => 'no such request']]),
        ]);
        $this->expectException(AgreelyNotFoundError::class);
        $this->client($http)->consentRequests()->cancel('0x' . str_repeat('a', 64));
    }

    public function testCatalogListReturnsTypedEntries(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['catalog' => [
                ['id' => 'cat_1', 'category' => 'Email Address', 'purpose' => 'Marketing Outreach', 'description' => null],
            ]]),
        ]);
        $entries = $this->client($http)->catalog()->list();
        $this->assertCount(1, $entries);
        $this->assertInstanceOf(CatalogEntry::class, $entries[0]);
        $this->assertSame('cat_1', $entries[0]->id);
        $this->assertNull($entries[0]->description);
        $this->assertSame('/v1/catalog', $http->calls[0]->path());
    }
}
