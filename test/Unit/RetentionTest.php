<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\AgreelyRateLimitError;
use Agreely\Sdk\Errors\AgreelySweepTooFrequentError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Resources\Retention;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\PurgeMethod;
use Agreely\Sdk\Types\RetentionAction;
use PHPUnit\Framework\TestCase;

/**
 * The host-retention surface: the reads, the two declarations, and above all the
 * REFUSALS. Every guard here exists because the same mistake reaches the server as a
 * 422 nobody can diagnose from a cron log, so each one is asserted to refuse BEFORE a
 * wire call (the mock's call list must stay empty).
 */
final class RetentionTest extends TestCase
{
    private const RULE = '6a1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b';

    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http]);
    }

    /** @return array<string,mixed> */
    private function ruleWire(string $key = self::RULE, string $action = RetentionAction::DESTROY): array
    {
        return [
            'key' => $key,
            'label' => 'Dossiers de bénévoles',
            'labelEn' => null,
            'duration' => ['value' => 24, 'unit' => 'months'],
            'trigger' => 'last_activity',
            'action' => $action,
            'anonymizationProcessKey' => null,
            'status' => 'active',
            'archivedAt' => null,
            'updatedAt' => '2026-09-25T12:00:00.000000Z',
        ];
    }

    /** @return array{ranAt:string,recordsAffected:int,method:string,coveredFrom:string,coveredUntil:string,hostSystem:string,hostCategory:string} */
    private function purgeInput(): array
    {
        return [
            'ranAt' => '2026-09-25T03:00:00-04:00',
            'recordsAffected' => 12,
            'method' => PurgeMethod::DESTROYED,
            'coveredFrom' => '2024-01-01',
            'coveredUntil' => '2024-06-30',
            'hostSystem' => 'billing',
            'hostCategory' => 'invoices',
        ];
    }

    /** @return array<string,mixed> */
    private function purgeWire(): array
    {
        return [
            'id' => 'f1e2d3c4-b5a6-7788-99aa-bbccddeeff00',
            'ruleKey' => self::RULE,
            'recorded' => true,
            'status' => 'declared',
            'replayed' => false,
            'ranAt' => '2026-09-25T07:00:00.000000Z',
            'recordsAffected' => 12,
            'method' => 'destroyed',
            'anonymizationProcessKey' => null,
            'coveredFrom' => '2024-01-01',
            'coveredUntil' => '2024-06-30',
            'hostSystem' => 'billing',
            'hostCategory' => 'invoices',
            'declaredBy' => 'sdk-retention',
            'declaredAt' => '2026-09-25T07:00:01.000000Z',
            'referencesRecorded' => 0,
            'warnings' => [],
        ];
    }

    // -----------------------------------------------------------------
    // The reads
    // -----------------------------------------------------------------

    public function testListRulesMapsTheListAndKeepsTheCompleteKeySet(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'cursor' => '2026-09-25T11:55:00.000000Z',
            'rules' => [$this->ruleWire()],
            'ruleKeys' => [self::RULE, 'other-key'],
        ])]);
        $list = $this->client($http)->retention()->listRules();

        $this->assertSame('/v1/retention/rules', $http->calls[0]->path());
        $this->assertSame('', $http->calls[0]->query());
        $this->assertSame('2026-09-25T11:55:00.000000Z', $list->cursor);
        $this->assertCount(1, $list->rules);
        $this->assertSame('Dossiers de bénévoles', $list->rules[0]->label);
        $this->assertSame(24, $list->rules[0]->duration->value);
        $this->assertSame('months', $list->rules[0]->duration->unit);
        // ruleKeys is the COMPLETE current set even on an incremental read.
        $this->assertSame([self::RULE, 'other-key'], $list->ruleKeys);
    }

    public function testListRulesSendsChangedSince(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['cursor' => 'c', 'rules' => [], 'ruleKeys' => []])]);
        $this->client($http)->retention()->listRules(['changedSince' => '2026-09-24T00:00:00Z']);
        $this->assertStringContainsString('changedSince=', $http->calls[0]->query());
    }

    public function testGetRuleMapsLastReportsAndTheirAbsence(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, $this->ruleWire() + [
            'lastReports' => [
                'purge' => ['ranAt' => '2026-09-01T00:00:00.000000Z', 'declaredAt' => '2026-09-01T00:01:00.000000Z'],
                'sweep' => null,
            ],
        ])]);
        $detail = $this->client($http)->retention()->getRule(self::RULE);

        $this->assertSame('/v1/retention/rules/' . self::RULE, $http->calls[0]->path());
        $this->assertSame(self::RULE, $detail->rule->key);
        $this->assertNotNull($detail->lastPurge);
        $this->assertSame('2026-09-01T00:00:00.000000Z', $detail->lastPurge->at);
        // A null report means the host declared none, NOT that none was due.
        $this->assertNull($detail->lastSweep);
    }

    public function testGetRuleRefusesABlankKeyBeforeAnyCall(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        $this->expectException(AgreelyConfigError::class);
        try {
            $this->client($http)->retention()->getRule('  ');
        } finally {
            $this->assertCount(0, $http->calls);
        }
    }

    // -----------------------------------------------------------------
    // declarePurge: the wire shape
    // -----------------------------------------------------------------

    public function testDeclarePurgeSendsTheHeaderKeyAndTheNamedBodyOnly(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, $this->purgeWire())]);
        $out = $this->client($http)->retention()->declarePurge(
            self::RULE,
            $this->purgeInput(),
            ['idempotencyKey' => 'run-4471:' . self::RULE],
        );

        $call = $http->calls[0];
        $this->assertSame('POST', $call->method);
        $this->assertSame('/v1/retention/rules/' . self::RULE . '/purges', $call->path());
        $this->assertSame('run-4471:' . self::RULE, $call->header('Idempotency-Key'));
        $this->assertNotNull($call->body);
        // The Idempotency-Key is a HEADER and never a body member.
        $this->assertArrayNotHasKey('idempotencyKey', $call->body);
        $this->assertSame([
            'ranAt',
            'recordsAffected',
            'method',
            'coveredFrom',
            'coveredUntil',
            'hostSystem',
            'hostCategory',
        ], array_keys($call->body));
        $this->assertSame('2026-09-25T03:00:00-04:00', $call->body['ranAt']);
        $this->assertSame('declared', $out->status);
        $this->assertFalse($out->replayed);
        $this->assertSame(0, $out->referencesRecorded);
    }

    public function testDeclarePurgeSendsADateTimeInUtc(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, $this->purgeWire())]);
        $input = $this->purgeInput();
        $input['ranAt'] = new \DateTimeImmutable('2026-09-25T03:00:00', new \DateTimeZone('-04:00'));
        $this->client($http)->retention()->declarePurge(self::RULE, $input, ['idempotencyKey' => 'k1']);
        $this->assertNotNull($http->calls[0]->body);
        $this->assertSame('2026-09-25T07:00:00.000Z', $http->calls[0]->body['ranAt']);
    }

    public function testDeclarePurgeCarriesTheAnonymizationProcessAndTheWarning(): void
    {
        $wire = $this->purgeWire();
        $wire['method'] = 'anonymized';
        $wire['anonymizationProcessKey'] = 'aa11bb22-cc33-dd44-ee55-ff6677889900';
        $wire['warnings'] = [[
            'code' => 'method_differs_from_rule',
            'message' => 'The rule says destroy.',
            'ruleAction' => 'destroy',
            'declaredMethod' => 'anonymized',
        ]];
        $http = new MockHttpClient([MockHttpClient::json(201, $wire)]);

        $input = $this->purgeInput();
        $input['method'] = PurgeMethod::ANONYMIZED;
        $input['anonymizationProcessKey'] = 'aa11bb22-cc33-dd44-ee55-ff6677889900';
        $out = $this->client($http)->retention()->declarePurge(self::RULE, $input, ['idempotencyKey' => 'k1']);

        $this->assertNotNull($http->calls[0]->body);
        $this->assertSame('aa11bb22-cc33-dd44-ee55-ff6677889900', $http->calls[0]->body['anonymizationProcessKey']);
        $this->assertCount(1, $out->warnings);
        // Flagged, never refused, and never called "non-compliant".
        $this->assertSame('method_differs_from_rule', $out->warnings[0]->code);
        $this->assertSame('destroy', $out->warnings[0]->ruleAction);
    }

    public function testDeclarePurgeSendsReferencesWhenGiven(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, $this->purgeWire())]);
        $input = $this->purgeInput();
        $input['references'] = ['ref-a', 'ref-b'];
        $this->client($http)->retention()->declarePurge(self::RULE, $input, ['idempotencyKey' => 'k1']);
        $this->assertNotNull($http->calls[0]->body);
        $this->assertSame(['ref-a', 'ref-b'], $http->calls[0]->body['references']);
    }

    public function testDeclarePurgeIsNeverAutoRetried(): void
    {
        // Two network failures in a row: an idempotent read would retry, a declaration
        // must not (a retry there is a second declaration, not a replay).
        $http = new MockHttpClient([MockHttpClient::network(), MockHttpClient::json(201, $this->purgeWire())]);
        $client = new Agreely([
            'apiKey' => 'k',
            'baseUrl' => 'https://api.test',
            'httpClient' => $http,
            'maxRetries' => 2,
        ]);
        try {
            $client->retention()->declarePurge(self::RULE, $this->purgeInput(), ['idempotencyKey' => 'k1']);
            $this->fail('expected the outage to surface');
        } catch (\Agreely\Sdk\Errors\AgreelyUnavailableError) {
            $this->assertCount(1, $http->calls);
        }
    }

    // -----------------------------------------------------------------
    // declarePurge: the refusals, each before any wire call
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $override
     * @param array<string,mixed> $options
     */
    private function assertPurgeRefused(array $override, string $expectInMessage, array $options = ['idempotencyKey' => 'k1']): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, $this->purgeWire())]);
        /** @var array<string,mixed> $input */
        $input = array_merge($this->purgeInput(), $override);
        foreach ($input as $key => $value) {
            if ($value === '__unset__') {
                unset($input[$key]);
            }
        }
        try {
            /** @phpstan-ignore argument.type, argument.type */
            $this->client($http)->retention()->declarePurge(self::RULE, $input, $options);
            $this->fail('expected AgreelyConfigError for: ' . $expectInMessage);
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString($expectInMessage, $e->getMessage());
            $this->assertCount(0, $http->calls, 'the refusal must happen before any wire call');
        }
    }

    public function testAnIdempotencyKeyInTheBodyIsRefusedAndPointedAtTheOptions(): void
    {
        $this->assertPurgeRefused(['idempotencyKey' => 'oops'], 'the Idempotency-Key is a HEADER');
    }

    public function testAMissingIdempotencyKeyIsRefused(): void
    {
        $this->assertPurgeRefused([], 'requires an "idempotencyKey" option', []);
    }

    public function testAnIdempotencyKeyWithNonPrintableCharactersIsRefused(): void
    {
        $this->assertPurgeRefused([], 'printable ASCII', ['idempotencyKey' => "run\n1"]);
    }

    public function testAnOverLongIdempotencyKeyIsRefusedRatherThanTruncated(): void
    {
        $this->assertPurgeRefused([], 'printable ASCII', ['idempotencyKey' => str_repeat('a', 256)]);
    }

    public function testAnUnknownBodyMemberIsRefusedRatherThanDropped(): void
    {
        $this->assertPurgeRefused(['recordCount' => 3], 'not a member of this input');
    }

    /** The rule's own word for the action is the single most likely wrong value here. */
    public function testTheRulesOwnActionIsRefusedAsAMethodAndTheRightWordIsNamed(): void
    {
        $this->assertPurgeRefused(['method' => RetentionAction::DESTROY], 'the method you meant is "destroyed"');
        $this->assertPurgeRefused(['method' => RetentionAction::ANONYMIZE], 'the method you meant is "anonymized"');
    }

    public function testAggregatedIsNotAThirdDisposition(): void
    {
        $this->assertPurgeRefused(['method' => 'aggregated'], 'Aggregation is a technique');
    }

    public function testAnonymizedWithoutItsProcessKeyIsRefused(): void
    {
        $this->assertPurgeRefused(['method' => PurgeMethod::ANONYMIZED], 'requires "anonymizationProcessKey"');
    }

    public function testDestroyedWithAProcessKeyIsRefused(): void
    {
        $this->assertPurgeRefused(['anonymizationProcessKey' => 'p'], 'takes no anonymizationProcessKey');
    }

    public function testRecordsAffectedBelowOnePointsAtTheSweep(): void
    {
        $this->assertPurgeRefused(['recordsAffected' => 0], 'call retention.declareSweep instead');
    }

    public function testRecordsAffectedAboveTheServerCapIsRefused(): void
    {
        $this->assertPurgeRefused(['recordsAffected' => Retention::PURGE_RECORDS_MAX + 1], 'from 1 to 1000000000');
    }

    public function testRecordsAffectedMustBeAWholeNumber(): void
    {
        $this->assertPurgeRefused(['recordsAffected' => 12.5], 'whole number');
    }

    public function testMoreThanOneThousandReferencesIsRefused(): void
    {
        $this->assertPurgeRefused(
            ['recordsAffected' => 2000, 'references' => array_fill(0, Retention::PURGE_REFERENCES_MAX + 1, 'r')],
            'at most 1000 references per declaration',
        );
    }

    public function testMoreReferencesThanRecordsAffectedIsRefused(): void
    {
        $this->assertPurgeRefused(
            ['recordsAffected' => 2, 'references' => ['a', 'b', 'c']],
            'references cannot outnumber recordsAffected',
        );
    }

    public function testAnEmptyReferenceListIsRefusedRatherThanSent(): void
    {
        $this->assertPurgeRefused(['references' => []], 'non-empty list');
    }

    public function testANaiveInstantIsRefusedRatherThanGuessed(): void
    {
        // No offset: the server would read it in its own zone and re-date the evidence.
        $this->assertPurgeRefused(['ranAt' => '2026-09-25 03:00:00'], 'explicit offset');
    }

    public function testACoveredDayMustBeACalendarDate(): void
    {
        $this->assertPurgeRefused(['coveredFrom' => '2024-01-01T00:00:00Z'], 'calendar date, YYYY-MM-DD');
    }

    public function testAnIdShapedHostSystemIsRefused(): void
    {
        // A pod name burns one of the 10 host systems allowed per 30 days, per deploy.
        $this->assertPurgeRefused(['hostSystem' => 'billing-7f9c8d6b5'], 'stable slug');
        $this->assertPurgeRefused(['hostSystem' => 'billing-00017'], 'stable slug');
        $this->assertPurgeRefused(['hostSystem' => 'Billing'], 'stable slug');
        $this->assertPurgeRefused(['hostCategory' => '6a1e2d3c4b5a'], 'stable slug');
    }

    public function testAWellFormedYearInAHostTokenIsStillAccepted(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, $this->purgeWire())]);
        $input = $this->purgeInput();
        $input['hostCategory'] = 'clients-2024';
        $this->client($http)->retention()->declarePurge(self::RULE, $input, ['idempotencyKey' => 'k1']);
        $this->assertNotNull($http->calls[0]->body);
        $this->assertSame('clients-2024', $http->calls[0]->body['hostCategory']);
    }

    // -----------------------------------------------------------------
    // declareSweep
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function sweepWire(): array
    {
        return [
            'id' => '11112222-3333-4444-5555-666677778888',
            'ruleKey' => self::RULE,
            'recorded' => true,
            'status' => 'declared',
            'declaredNothingDue' => true,
            'replayed' => false,
            'sweptAt' => '2026-09-25T07:00:00.000000Z',
            'hostSystem' => 'billing',
            'declaredBy' => 'sdk-retention',
            'declaredAt' => '2026-09-25T07:00:01.000000Z',
        ];
    }

    public function testDeclareSweepSendsTheHeaderKeyAndTwoBodyMembers(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, $this->sweepWire())]);
        $out = $this->client($http)->retention()->declareSweep(
            self::RULE,
            ['sweptAt' => new \DateTimeImmutable('now'), 'hostSystem' => 'billing'],
            ['idempotencyKey' => 'nightly-2026-09-25:' . self::RULE],
        );

        $call = $http->calls[0];
        $this->assertSame('/v1/retention/rules/' . self::RULE . '/sweeps', $call->path());
        $this->assertSame('nightly-2026-09-25:' . self::RULE, $call->header('Idempotency-Key'));
        $this->assertNotNull($call->body);
        $this->assertSame(['sweptAt', 'hostSystem'], array_keys($call->body));
        $this->assertTrue($out->declaredNothingDue);
        $this->assertSame('declared', $out->status);
    }

    /**
     * ⚠️ The trap nobody guesses from a remote 422: a queued retry from yesterday, or a
     * cron on a skewed clock, declaring a pass the server will no longer accept.
     */
    public function testASweptAtOlderThanTwentyFourHoursIsRefusedBeforeTheWireCall(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, $this->sweepWire())]);
        try {
            $this->client($http)->retention()->declareSweep(
                self::RULE,
                ['sweptAt' => new \DateTimeImmutable('-25 hours'), 'hostSystem' => 'billing'],
                ['idempotencyKey' => 'k1'],
            );
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('within the last 24 hours', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    public function testASweptAtJustInsideTheWindowIsAccepted(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, $this->sweepWire())]);
        $this->client($http)->retention()->declareSweep(
            self::RULE,
            ['sweptAt' => new \DateTimeImmutable('-23 hours -30 minutes'), 'hostSystem' => 'billing'],
            ['idempotencyKey' => 'k1'],
        );
        $this->assertCount(1, $http->calls);
    }

    public function testASweepRefusesAnIdempotencyKeyInTheBody(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, $this->sweepWire())]);
        try {
            $this->client($http)->retention()->declareSweep(
                self::RULE,
                ['sweptAt' => '2026-09-25T03:00:00-04:00', 'hostSystem' => 'billing', 'idempotencyKey' => 'oops'],
                ['idempotencyKey' => 'k1'],
            );
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError $e) {
            $this->assertStringContainsString('the Idempotency-Key is a HEADER', $e->getMessage());
            $this->assertCount(0, $http->calls);
        }
    }

    // -----------------------------------------------------------------
    // The typed errors
    // -----------------------------------------------------------------

    public function testSweepTooFrequentIsItsOwnTypedRateLimitCarryingRetryAfter(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(
            429,
            ['error' => ['code' => 'sweep_too_frequent', 'message' => 'A pass was declared 4 minutes ago.']],
            ['Retry-After' => 660],
        )]);
        try {
            $this->client($http)->retention()->declareSweep(
                self::RULE,
                ['sweptAt' => new \DateTimeImmutable('now'), 'hostSystem' => 'billing'],
                ['idempotencyKey' => 'k1'],
            );
            $this->fail('expected AgreelySweepTooFrequentError');
        } catch (AgreelySweepTooFrequentError $e) {
            // A generic rate-limit catch still catches it.
            $this->assertInstanceOf(AgreelyRateLimitError::class, $e);
            $this->assertSame('sweep_too_frequent', $e->code);
            $this->assertSame(660, $e->retryAfterSeconds);
            $this->assertCount(1, $http->calls);
        }
    }

    /** The 15-minute floor is never waited out and re-sent: that would record a second pass. */
    public function testSweepTooFrequentIsNeverAutoRetriedEvenWithMaxRetriesSet(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(
            429,
            ['error' => ['code' => 'sweep_too_frequent', 'message' => 'floor']],
            ['Retry-After' => 1],
        )]);
        $client = new Agreely([
            'apiKey' => 'k',
            'baseUrl' => 'https://api.test',
            'httpClient' => $http,
            'maxRetries' => 3,
        ]);
        // Read path too: idempotentRetry would otherwise let the 429 loop.
        $this->expectException(AgreelySweepTooFrequentError::class);
        try {
            $client->retention()->listRules();
        } finally {
            $this->assertCount(1, $http->calls);
        }
    }

    public function testAPlainRateLimitStaysTheGenericError(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(
            429,
            ['error' => ['code' => 'rate_limited', 'message' => 'window']],
            ['Retry-After' => 30],
        )]);
        try {
            $this->client($http)->retention()->listRules();
            $this->fail('expected AgreelyRateLimitError');
        } catch (AgreelyRateLimitError $e) {
            $this->assertNotInstanceOf(AgreelySweepTooFrequentError::class, $e);
            $this->assertSame(30, $e->retryAfter);
        }
    }

    /** 409 means « retry with the SAME key », which is the opposite of a fresh attempt. */
    public function testAConflictIsItsOwnErrorAndNotARateLimitOrAnOutage(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(
            409,
            ['error' => ['code' => 'retry', 'message' => 'A concurrent retry could not be settled.']],
        )]);
        try {
            $this->client($http)->retention()->declarePurge(
                self::RULE,
                $this->purgeInput(),
                ['idempotencyKey' => 'k1'],
            );
            $this->fail('expected AgreelyConflictError');
        } catch (AgreelyConflictError $e) {
            // Neither a rate limit nor an outage, which the class hierarchy already
            // guarantees: AgreelyConflictError extends AgreelyError directly.
            $this->assertSame(409, $e->status);
            $this->assertSame('retry', $e->code);
        }
    }

    /** 413 is a body that can never be accepted, so it must not read as a transient outage. */
    public function testAnOverLargeBodyIsAValidationErrorNotAnOutage(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(
            413,
            ['error' => ['code' => 'body_too_large', 'message' => 'The request body exceeds 262144 bytes.']],
        )]);
        try {
            $this->client($http)->retention()->listRules();
            $this->fail('expected AgreelyValidationError');
        } catch (AgreelyValidationError $e) {
            // A validation error, so NOT an outage: the transport's default 5xx branch
            // would have made this retryable on a body that can never be accepted.
            $this->assertSame(413, $e->status);
            $this->assertSame('body_too_large', $e->code);
        }
    }

    // -----------------------------------------------------------------
    // The two vocabularies
    // -----------------------------------------------------------------

    public function testTheRuleActionAndThePurgeMethodAreDisjointVocabularies(): void
    {
        $this->assertSame([], array_intersect(RetentionAction::ALL, PurgeMethod::ALL));
        $this->assertSame(PurgeMethod::DESTROYED, PurgeMethod::forRule(RetentionAction::DESTROY));
        $this->assertSame(PurgeMethod::ANONYMIZED, PurgeMethod::forRule(RetentionAction::ANONYMIZE));
    }

    public function testForRuleRefusesADeclarationWord(): void
    {
        $this->expectException(AgreelyConfigError::class);
        PurgeMethod::forRule(PurgeMethod::DESTROYED);
    }
}
