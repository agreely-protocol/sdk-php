<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyAuthError;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyUnavailableError;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\HoldsSync;
use PHPUnit\Framework\TestCase;

final class HoldFeedTest extends TestCase
{
    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http]);
    }

    /** @return array<string,mixed> */
    private static function row(string $id, string $status = 'active', mixed $scope = 'all'): array
    {
        return ['id' => $id, 'customerRef' => 'c-' . $id, 'status' => $status, 'scope' => $scope, 'changedAt' => '2026-10-09T12:00:00Z'];
    }

    public function testListHoldsReadsOnePageAndPassesTheQuery(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'holds' => [self::row('1', 'released', ['rules' => ['r-1'], 'cells' => ['c-9']])],
            'nextPageToken' => 'tok-2',
            'cursor' => null,
        ])]);
        $page = $this->client($http)->retention()->listHolds(['changedSince' => '2026-10-08T00:00:00Z']);
        $this->assertSame('GET', $http->calls[0]->method);
        $this->assertSame('/v1/retention/holds', $http->calls[0]->path());
        $this->assertSame('changedSince=2026-10-08T00%3A00%3A00Z', $http->calls[0]->query());
        $this->assertFalse($page->isLast());
        $this->assertSame('tok-2', $page->nextPageToken);
        $hold = $page->holds[0];
        $this->assertFalse($hold->isActive());
        $this->assertSame(['r-1'], $hold->scope->rules);
        $this->assertTrue($hold->scope->covers(null, 'c-9'));
        $this->assertSame('c-1', $hold->customerRef);
    }

    public function testSyncHoldsFollowsThePagesAndReturnsTheSnapshotWithItsCursor(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['holds' => [self::row('1'), self::row('2')], 'nextPageToken' => 'tok-2', 'cursor' => null]),
            MockHttpClient::json(200, ['holds' => [self::row('3', 'released')], 'nextPageToken' => null, 'cursor' => '2026-10-09T11:55:00Z']),
        ]);
        $sync = $this->client($http)->retention()->syncHolds();
        $this->assertSame(HoldsSync::MODE_SNAPSHOT, $sync->mode);
        $this->assertSame(['1', '2', '3'], array_map(static fn ($h) => $h->id, $sync->holds));
        $this->assertSame(['1', '2'], array_map(static fn ($h) => $h->id, $sync->active()));
        $this->assertSame('2026-10-09T11:55:00Z', $sync->cursor);
        $this->assertSame('', $http->calls[0]->query(), 'a snapshot sends no changedSince');
        $this->assertSame('pageToken=tok-2', $http->calls[1]->query(), 'the next page sends only its token');
    }

    public function testADeltaSyncStartsFromTheStoredCursor(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['holds' => [], 'nextPageToken' => null, 'cursor' => 'c2'])]);
        $sync = $this->client($http)->retention()->syncHolds(['changedSince' => 'c1']);
        $this->assertSame(HoldsSync::MODE_DELTA, $sync->mode);
        $this->assertSame([], $sync->holds);
        $this->assertSame('c2', $sync->cursor);
        $this->assertSame('changedSince=c1', $http->calls[0]->query());
    }

    public function testHoldPagesYieldsEachPage(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['holds' => [self::row('1')], 'nextPageToken' => 'tok-2', 'cursor' => null]),
            MockHttpClient::json(200, ['holds' => [self::row('2')], 'nextPageToken' => null, 'cursor' => 'c']),
        ]);
        $pages = iterator_to_array($this->client($http)->retention()->holdPages(), false);
        $this->assertCount(2, $pages);
        $this->assertFalse($pages[0]->isLast());
        $this->assertTrue($pages[1]->isLast());
        $this->assertSame('c', $pages[1]->cursor);
    }

    public function testAnErrorMidFeedThrowsAndReturnsNothing(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, ['holds' => [self::row('1')], 'nextPageToken' => 'tok-2', 'cursor' => null]),
            MockHttpClient::json(503, ['error' => ['code' => 'unavailable', 'message' => 'down']]),
        ]);
        $this->expectException(AgreelyUnavailableError::class);
        $this->client($http)->retention()->syncHolds();
    }

    public function testAMissingHoldsScopeIsAnAuthError(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(403, ['error' => ['code' => 'forbidden', 'message' => 'no holds']])]);
        $this->expectException(AgreelyAuthError::class);
        $this->client($http)->retention()->syncHolds();
    }

    public function testAFeedLongerThanMaxPagesThrowsRatherThanReturningAPartialSet(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['holds' => [self::row('1')], 'nextPageToken' => 'again', 'cursor' => null])]);
        try {
            $this->client($http)->retention()->syncHolds(['maxPages' => 3]);
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('INCOMPLETE', $e->getMessage());
            $this->assertCount(3, $http->calls);
        }
    }

    public function testListHoldsRefusesAnUnknownMemberOrAList(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        foreach ([['cursor' => 'x'], ['changedSince' => ['a']]] as $input) {
            try {
                /** @phpstan-ignore argument.type (the point is a shape the type forbids) */
                $this->client($http)->retention()->listHolds($input);
                $this->fail('expected AgreelyConfigError');
            } catch (AgreelyConfigError) {
                $this->assertCount(0, $http->calls);
            }
        }
    }
}
