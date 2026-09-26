<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Degrade\RetentionDegradeContext;
use Agreely\Sdk\Errors\AgreelyAuthError;
use Agreely\Sdk\Errors\AgreelyBillingInactiveError;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyRateLimitError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\RetentionRuleSnapshot;
use Agreely\Sdk\Types\RulesForPurge;
use PHPUnit\Framework\TestCase;

/**
 * THE OTHER DIRECTION OF FAILING CLOSED. For a consent check, failing closed means
 * denying; for a purge job it means NOT PURGING, because destroying on stale rules can
 * destroy what a LENGTHENED rule (a legal hold) required you to keep, and that never
 * repairs.
 *
 * So the assertions here are mostly about what does NOT happen: an outage hands back no
 * rules, reading them throws rather than returning an empty list, and anything that is
 * not an outage surfaces as its own error instead of passing as a skipped night.
 */
final class RetentionDegradeTest extends TestCase
{
    private const RULE_A = 'aaaa1111-2222-3333-4444-555566667777';
    private const RULE_B = 'bbbb1111-2222-3333-4444-555566667777';

    private function client(MockHttpClient $http, ?string $maxDegradeWindow = null): Agreely
    {
        $options = ['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http];
        if ($maxDegradeWindow !== null) {
            $options['maxDegradeWindow'] = $maxDegradeWindow;
        }
        return new Agreely($options);
    }

    /** @return array<string,mixed> */
    private function ruleWire(string $key, int $months): array
    {
        return [
            'key' => $key,
            'label' => 'Dossiers',
            'labelEn' => null,
            'duration' => ['value' => $months, 'unit' => 'months'],
            'trigger' => 'collection',
            'action' => 'destroy',
            'anonymizationProcessKey' => null,
            'status' => 'active',
            'archivedAt' => null,
            'updatedAt' => '2026-09-25T12:00:00.000000Z',
        ];
    }

    private function snapshot(string $fetchedAt, int $months = 24): RetentionRuleSnapshot
    {
        $rebuilt = RetentionRuleSnapshot::fromArray([
            'cursor' => '2026-09-24T00:00:00.000000Z',
            'fetchedAt' => $fetchedAt,
            'rules' => [$this->ruleWire(self::RULE_A, $months)],
        ]);
        $this->assertNotNull($rebuilt);
        return $rebuilt;
    }

    // -----------------------------------------------------------------
    // The healthy path
    // -----------------------------------------------------------------

    public function testAFirstRunReadsLiveAndReturnsASnapshotToPersist(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'cursor' => '2026-09-25T11:55:00.000000Z',
            'rules' => [$this->ruleWire(self::RULE_A, 24)],
            'ruleKeys' => [self::RULE_A],
        ])]);
        $result = $this->client($http)->retention()->rulesForPurge();

        $this->assertSame(RulesForPurge::SOURCE_LIVE, $result->source);
        $this->assertTrue($result->mayPurge());
        $this->assertFalse($result->isDegraded());
        $this->assertCount(1, $result->rules());
        $this->assertNotNull($result->snapshot);
        $this->assertSame('2026-09-25T11:55:00.000000Z', $result->snapshot->cursor);
        $this->assertNotSame('', $result->snapshot->fetchedAt);
    }

    public function testASnapshotReadsIncrementallyAndMergesTheChangedRuleOver(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'cursor' => '2026-09-25T11:55:00.000000Z',
            // The rule was SHORTENED to 6 months since the stored snapshot said 24.
            'rules' => [$this->ruleWire(self::RULE_A, 6)],
            'ruleKeys' => [self::RULE_A],
        ])]);
        $result = $this->client($http)->retention()->rulesForPurge([
            'snapshot' => $this->snapshot(gmdate('Y-m-d\TH:i:s\Z'), 24),
        ]);

        $this->assertCount(1, $http->calls);
        $this->assertStringContainsString('changedSince=', $http->calls[0]->query());
        $this->assertSame(RulesForPurge::SOURCE_LIVE, $result->source);
        $this->assertSame(6, $result->rules()[0]->duration->value);
    }

    /** A key the merge cannot account for means one honest full read, never a partial set. */
    public function testAnUnaccountedCurrentKeyFallsBackToAFullRead(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(200, [
                'cursor' => 'c1',
                'rules' => [],
                // RULE_B is current but was never in the snapshot and did not change.
                'ruleKeys' => [self::RULE_A, self::RULE_B],
            ]),
            MockHttpClient::json(200, [
                'cursor' => 'c2',
                'rules' => [$this->ruleWire(self::RULE_A, 24), $this->ruleWire(self::RULE_B, 12)],
                'ruleKeys' => [self::RULE_A, self::RULE_B],
            ]),
        ]);
        $result = $this->client($http)->retention()->rulesForPurge([
            'snapshot' => $this->snapshot(gmdate('Y-m-d\TH:i:s\Z')),
        ]);

        $this->assertCount(2, $http->calls);
        $this->assertSame('', $http->calls[1]->query(), 'the fallback read sends no cursor');
        $this->assertCount(2, $result->rules());
    }

    public function testARefusedCursorFallsBackToAFullRead(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(422, ['error' => ['code' => 'invalid_request', 'message' => 'bad cursor']]),
            MockHttpClient::json(200, [
                'cursor' => 'c2',
                'rules' => [$this->ruleWire(self::RULE_A, 24)],
                'ruleKeys' => [self::RULE_A],
            ]),
        ]);
        $result = $this->client($http)->retention()->rulesForPurge([
            'snapshot' => $this->snapshot(gmdate('Y-m-d\TH:i:s\Z')),
        ]);
        $this->assertCount(2, $http->calls);
        $this->assertSame(RulesForPurge::SOURCE_LIVE, $result->source);
    }

    public function testASnapshotRoundTripsThroughItsOwnArrayForm(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'cursor' => 'c1',
            'rules' => [$this->ruleWire(self::RULE_A, 24)],
            'ruleKeys' => [self::RULE_A],
        ])]);
        $snapshot = $this->client($http)->retention()->rulesForPurge()->snapshot;
        $this->assertNotNull($snapshot);

        $stored = json_encode($snapshot->toArray());
        $this->assertIsString($stored);
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($stored, true);
        $rebuilt = RetentionRuleSnapshot::fromArray($decoded);

        $this->assertNotNull($rebuilt);
        $this->assertSame($snapshot->cursor, $rebuilt->cursor);
        $this->assertSame($snapshot->fetchedAt, $rebuilt->fetchedAt);
        $this->assertSame(24, $rebuilt->rules[0]->duration->value);
    }

    public function testAnUnusableStoredShapeRebuildsAsNullRatherThanHalfASnapshot(): void
    {
        $this->assertNull(RetentionRuleSnapshot::fromArray(null));
        $this->assertNull(RetentionRuleSnapshot::fromArray([]));
        $this->assertNull(RetentionRuleSnapshot::fromArray(['cursor' => 'c', 'fetchedAt' => 'x']));
        $this->assertNull(RetentionRuleSnapshot::fromArray(['cursor' => '', 'fetchedAt' => 'x', 'rules' => []]));
        $this->assertNull(RetentionRuleSnapshot::fromArray(['cursor' => 'c', 'fetchedAt' => 'x', 'rules' => ['no']]));
    }

    // -----------------------------------------------------------------
    // The outage: abstain is the default, and it is a decision
    // -----------------------------------------------------------------

    public function testAnOutageAbstainsByDefaultAndCarriesNoRules(): void
    {
        $http = new MockHttpClient([MockHttpClient::network()]);
        $result = $this->client($http)->retention()->rulesForPurge();

        $this->assertSame(RulesForPurge::SOURCE_ABSTAIN, $result->source);
        $this->assertFalse($result->mayPurge());
        $this->assertSame(RulesForPurge::REASON_OUTAGE, $result->reason);
        $this->assertNotNull($result->error);
        $this->assertNull($result->snapshot);
    }

    /** An empty list would read as "nothing to purge" and hide a missed run. */
    public function testReadingTheRulesOffAnAbstainThrowsRatherThanReturningAnEmptyList(): void
    {
        $http = new MockHttpClient([MockHttpClient::timeout()]);
        $result = $this->client($http)->retention()->rulesForPurge();
        $this->expectException(AgreelyConfigError::class);
        $this->expectExceptionMessageMatches('/must not purge/');
        $result->rules();
    }

    public function testOptingIntoTheStoredRulesPurgesAndEmitsExactlyOneEvidenceRecord(): void
    {
        $http = new MockHttpClient([MockHttpClient::network()]);
        /** @var list<RetentionDegradeContext> $emitted */
        $emitted = [];
        $snapshot = $this->snapshot(gmdate('Y-m-d\TH:i:s\Z', time() - 3600));

        $result = $this->client($http)->retention()->rulesForPurge([
            'snapshot' => $snapshot,
            'onOutage' => 'use-snapshot',
            'maxSnapshotAge' => '6h',
            'onDegrade' => static function (RetentionDegradeContext $ctx) use (&$emitted): void {
                $emitted[] = $ctx;
            },
        ]);

        $this->assertSame(RulesForPurge::SOURCE_SNAPSHOT, $result->source);
        $this->assertTrue($result->mayPurge());
        $this->assertTrue($result->isDegraded());
        $this->assertCount(1, $result->rules());
        $this->assertCount(1, $emitted);
        $this->assertSame($snapshot->fetchedAt, $emitted[0]->snapshotFetchedAt);
        $this->assertGreaterThan(3_000_000.0, $emitted[0]->staleForMs);
        $this->assertNotNull($result->staleForMs);
    }

    public function testASnapshotOlderThanTheWindowAbstainsAndEmitsNothing(): void
    {
        $http = new MockHttpClient([MockHttpClient::network()]);
        $emitted = 0;
        $result = $this->client($http)->retention()->rulesForPurge([
            'snapshot' => $this->snapshot(gmdate('Y-m-d\TH:i:s\Z', time() - 7200)),
            'onOutage' => 'use-snapshot',
            'maxSnapshotAge' => '1h',
            'onDegrade' => static function () use (&$emitted): void {
                $emitted++;
            },
        ]);

        $this->assertSame(RulesForPurge::SOURCE_ABSTAIN, $result->source);
        $this->assertSame(RulesForPurge::REASON_SNAPSHOT_TOO_OLD, $result->reason);
        // Nothing was destroyed, so there is nothing to account for.
        $this->assertSame(0, $emitted);
    }

    public function testOptingInWithNoSnapshotAbstains(): void
    {
        $http = new MockHttpClient([MockHttpClient::network()]);
        $result = $this->client($http)->retention()->rulesForPurge([
            'onOutage' => 'use-snapshot',
            'maxSnapshotAge' => '6h',
            'onDegrade' => static function (): void {
            },
        ]);
        $this->assertSame(RulesForPurge::SOURCE_ABSTAIN, $result->source);
        $this->assertSame(RulesForPurge::REASON_NO_SNAPSHOT, $result->reason);
    }

    // -----------------------------------------------------------------
    // The misconfigurations, refused on a HEALTHY night
    // -----------------------------------------------------------------

    public function testOptingInWithoutAnEvidenceSinkIsRefusedBeforeAnyWireCall(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['cursor' => 'c', 'rules' => [], 'ruleKeys' => []])]);
        try {
            $this->client($http)->retention()->rulesForPurge([
                'onOutage' => 'use-snapshot',
                'maxSnapshotAge' => '6h',
            ]);
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('onDegrade is mandatory', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    public function testOptingInWithoutABoundedWindowIsRefused(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['cursor' => 'c', 'rules' => [], 'ruleKeys' => []])]);
        try {
            $this->client($http)->retention()->rulesForPurge([
                'onOutage' => 'use-snapshot',
                'onDegrade' => static function (): void {
                },
            ]);
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('maxSnapshotAge is mandatory', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    public function testAnUnboundedWindowIsRefusedAndRaisingTheClientCapAllowsThePreviousNightlyRun(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['cursor' => 'c', 'rules' => [], 'ruleKeys' => []])]);
        $options = [
            'onOutage' => 'use-snapshot',
            'maxSnapshotAge' => '26h',
            'onDegrade' => static function (): void {
            },
        ];
        try {
            $this->client($http)->retention()->rulesForPurge($options);
            $this->fail('expected the 24h default cap to refuse 26h');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('exceeds the maximum allowed window', $e->getMessage());
        }
        // The cap is a client decision, so raising it deliberately is allowed.
        $result = $this->client($http, '30h')->retention()->rulesForPurge($options);
        $this->assertSame(RulesForPurge::SOURCE_LIVE, $result->source);
    }

    public function testAnUnknownOnOutageWordIsRefused(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['cursor' => 'c', 'rules' => [], 'ruleKeys' => []])]);
        $this->expectException(AgreelyConfigError::class);
        $this->expectExceptionMessageMatches('/onOutage must be/');
        $this->client($http)->retention()->rulesForPurge(['onOutage' => 'allow']);
    }

    public function testARawArrayIsNotASnapshot(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['cursor' => 'c', 'rules' => [], 'ruleKeys' => []])]);
        $this->expectException(AgreelyConfigError::class);
        $this->expectExceptionMessageMatches('/RetentionRuleSnapshot::fromArray/');
        /** @phpstan-ignore argument.type */
        $this->client($http)->retention()->rulesForPurge(['snapshot' => ['cursor' => 'c']]);
    }

    // -----------------------------------------------------------------
    // Not an outage: it must THROW, never pass as a skipped night
    // -----------------------------------------------------------------

    /**
     * @return iterable<string,array{int,array<string,mixed>,class-string<\Throwable>}>
     */
    public static function nonOutages(): iterable
    {
        yield 'a revoked or unscoped key' => [
            403,
            ['error' => ['code' => 'forbidden', 'message' => 'missing scope']],
            AgreelyAuthError::class,
        ];
        yield 'a lapsed subscription' => [
            402,
            ['error' => ['code' => 'billing_inactive', 'message' => 'pay']],
            AgreelyBillingInactiveError::class,
        ];
        yield 'a refused request' => [
            422,
            ['error' => ['code' => 'invalid_request', 'message' => 'bad']],
            AgreelyValidationError::class,
        ];
        yield 'the company rate window' => [
            429,
            ['error' => ['code' => 'rate_limited', 'message' => 'slow down']],
            AgreelyRateLimitError::class,
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @param class-string<\Throwable> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonOutages')]
    public function testANonOutageThrowsEvenWithUseSnapshotConfigured(int $status, array $body, string $expected): void
    {
        $http = new MockHttpClient([MockHttpClient::json($status, $body)]);
        $this->expectException($expected);
        $this->client($http)->retention()->rulesForPurge([
            'snapshot' => $this->snapshot(gmdate('Y-m-d\TH:i:s\Z')),
            'onOutage' => 'use-snapshot',
            'maxSnapshotAge' => '6h',
            'onDegrade' => static function (): void {
            },
        ]);
    }
}
