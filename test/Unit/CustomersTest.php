<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\AgreelyDailyCapError;
use Agreely\Sdk\Errors\ErrorCode;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\AgreelyIdentity;
use Agreely\Sdk\Types\Disposition;
use Agreely\Sdk\Types\DispositionWarning;
use Agreely\Sdk\Types\RetentionClock;
use Agreely\Sdk\Types\RetentionHold;
use PHPUnit\Framework\TestCase;

final class CustomersTest extends TestCase
{
    private const HOLD = '6f1c2d3e-4a5b-4c6d-8e7f-901234567890';
    private const RULE = '11111111-2222-4333-8444-555555555555';

    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http]);
    }

    /** @return array<string,mixed> */
    private static function record(): array
    {
        return [
            'customerRef' => 'STORE/0042', 'registered' => true, 'source' => 'api', 'hasDisplayName' => true,
            'hasEmail' => false, 'hasBasisNote' => false, 'legalBasis' => 'contract', 'noticeLocale' => 'fr',
            'createdAt' => '2026-10-09T12:00:00Z', 'updatedAt' => '2026-10-09T12:00:00Z',
            'relationship' => ['status' => 'active', 'endedAt' => null],
        ];
    }

    /** @return array<string,mixed> */
    private static function apiHold(string $status = 'active'): array
    {
        return [
            'id' => self::HOLD, 'status' => $status, 'scope' => ['rules' => [self::RULE], 'cells' => []],
            'placedBy' => 'api', 'startedOn' => '2026-10-09', 'placedAt' => '2026-10-09T12:00:00Z',
            'releasedAt' => $status === 'released' ? '2026-10-10T12:00:00Z' : null, 'ground' => 'other_law',
            'hasProvision' => true, 'reviewOn' => null, 'hasReleaseReason' => $status === 'released',
        ];
    }

    private function refused(callable $call): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [])]);
        try {
            $call($this->client($http));
            $this->fail('expected AgreelyConfigError');
        } catch (AgreelyConfigError) {
            $this->assertCount(0, $http->calls, 'refused before any wire call');
        }
    }

    // --- identity ---------------------------------------------------------------------------------------------------

    public function testUpsertIsAMergeThatSendsExactlyTheFieldsGivenNullsIncluded(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, self::record())]);
        $record = $this->client($http)->customers()->upsert('STORE/0042', [
            'displayName' => 'Marie Tremblay',
            'email' => null,
            'legalBasis' => 'contract',
        ]);
        $call = $http->calls[0];
        $this->assertSame('PUT', $call->method);
        $this->assertSame('/v1/customers/STORE%2F0042', parse_url($call->url, PHP_URL_PATH));
        $this->assertSame(['displayName' => 'Marie Tremblay', 'email' => null, 'legalBasis' => 'contract'], $call->body);
        $this->assertTrue($record->created);
        $this->assertTrue($record->hasDisplayName);
        $this->assertSame('contract', $record->legalBasis);
        $this->assertSame('active', $record->relationship->status);
    }

    public function testUpsertSaysWhenItMergedIntoAnExistingRow(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, self::record())]);
        $this->assertFalse($this->client($http)->customers()->upsert('c-1', ['noticeLocale' => 'en'])->created);
    }

    public function testAnEmptyMergeSendsNoBody(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, self::record())]);
        $this->client($http)->customers()->upsert('c-1', []);
        $this->assertNull($http->calls[0]->body);
    }

    public function testUpsertRefusesWhatTheServerWouldRefuse(): void
    {
        $this->refused(fn (Agreely $a) => $a->customers()->upsert('c-1', ['legalBasis' => 'consent']));
        $this->refused(fn (Agreely $a) => $a->customers()->upsert('c-1', ['noticeLocale' => 'de']));
        $this->refused(fn (Agreely $a) => $a->customers()->upsert('c-1', ['displayName' => str_repeat('n', 201)]));
        /** @phpstan-ignore argument.type (the point is a shape the type forbids) */
        $this->refused(fn (Agreely $a) => $a->customers()->upsert('c-1', ['email' => 42]));
        $this->refused(fn (Agreely $a) => $a->customers()->upsert('c-1', ['phone' => '555']));
        $this->refused(fn (Agreely $a) => $a->customers()->upsert('  ', ['email' => 'a@b.c']));
        $this->refused(fn (Agreely $a) => $a->customers()->upsert(str_repeat('r', 201), []));
    }

    public function testAClosedIdentityIsANamedConflict(): void
    {
        foreach ([ErrorCode::IDENTITY_HELD, ErrorCode::IDENTITY_ERASED] as $code) {
            $http = new MockHttpClient([MockHttpClient::json(409, ['error' => ['code' => $code, 'message' => 'closed']])]);
            try {
                $this->client($http)->customers()->upsert('c-1', ['email' => 'a@b.c']);
                $this->fail('expected AgreelyConflictError');
            } catch (AgreelyConflictError $e) {
                $this->assertSame($code, $e->code);
                $this->assertFalse($e->isRetryable());
            }
        }
    }

    public function testGetReadsMetadataOnly(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, self::record())]);
        $record = $this->client($http)->customers()->get('STORE/0042');
        $this->assertSame('GET', $http->calls[0]->method);
        $this->assertNull($record->created);
        $this->assertFalse($record->hasEmail);
        $this->assertSame('api', $record->source);
    }

    // --- retention posture ------------------------------------------------------------------------------------------

    public function testRetentionReadsTheClockTheStandingDispositionAndTheHolds(): void
    {
        $organisationHold = [
            'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'status' => 'active', 'scope' => 'all',
            'placedBy' => 'organization', 'startedOn' => '2026-10-01', 'placedAt' => '2026-10-01T12:00:00Z',
            'releasedAt' => null,
        ];
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'customerRef' => 'c-1',
            'relationship' => ['status' => 'ended', 'endedAt' => '2026-09-30T12:00:00Z'],
            'clock' => [
                'trigger' => 'purpose_achieved', 'recipe' => 'dueAt = ...', 'dueAt' => null, 'reason' => 'hold_active',
                'rules' => [[
                    'ruleId' => self::RULE, 'label' => 'Dossiers', 'labelEn' => null, 'periodMonths' => 24,
                    'action' => 'destroy', 'dueAt' => '2028-09-30', 'held' => true,
                ]],
            ],
            'disposition' => [
                'id' => 'd-1', 'disposition' => 'destroyed', 'retentionUntil' => null, 'hasReason' => false,
                'endedAtSnapshot' => '2026-09-30T12:00:00Z', 'scheduleRef' => null, 'basis' => 'contract',
                'hasBasisNote' => false, 'declaredBy' => 'api:purge', 'declaredAt' => '2026-10-01T00:00:00Z',
                'agreelyIdentity' => 'retained_hold',
            ],
            'holds' => [$organisationHold, self::apiHold('released')],
            'releasedTruncated' => false,
        ])]);
        $posture = $this->client($http)->retention()->getCustomerRetention('c-1');
        $this->assertSame('/v1/customers/c-1/retention', $http->calls[0]->path());
        $this->assertTrue($posture->relationship->isEnded());
        $this->assertNull($posture->clock->dueAt);
        $this->assertSame(RetentionClock::REASON_HOLD_ACTIVE, $posture->clock->reason);
        $this->assertTrue($posture->clock->rules[0]->held);
        $this->assertSame(24, $posture->clock->rules[0]->periodMonths);
        $this->assertNotNull($posture->disposition);
        $this->assertSame(AgreelyIdentity::RETAINED_HOLD, $posture->disposition->agreelyIdentity);
        $this->assertCount(2, $posture->holds);
        $this->assertCount(1, $posture->activeHolds());

        $organisation = $posture->holds[0];
        $this->assertTrue($organisation->scope->all);
        $this->assertTrue($organisation->scope->covers(self::RULE));
        $this->assertSame(RetentionHold::PLACED_BY_ORGANIZATION, $organisation->placedBy);
        $this->assertNull($organisation->ground, 'a hold the host did not place says only that it holds');
        $this->assertNull($organisation->hasProvision);

        $own = $posture->holds[1];
        $this->assertFalse($own->scope->all);
        $this->assertTrue($own->scope->covers(self::RULE));
        $this->assertFalse($own->scope->covers('another-rule'));
        $this->assertSame('other_law', $own->ground);
        $this->assertTrue($own->hasReleaseReason);
    }

    public function testRetentionWithNoStandingDisposition(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'customerRef' => 'c-1', 'relationship' => ['status' => 'active', 'endedAt' => null],
            'clock' => ['trigger' => 'purpose_achieved', 'recipe' => 'r', 'dueAt' => null, 'reason' => 'no_declared_rule', 'rules' => []],
            'disposition' => null, 'holds' => [], 'releasedTruncated' => false,
        ])]);
        $posture = $this->client($http)->retention()->getCustomerRetention('c-1');
        $this->assertNull($posture->disposition);
        $this->assertSame(RetentionClock::REASON_NO_DECLARED_RULE, $posture->clock->reason);
    }

    // --- dispositions -----------------------------------------------------------------------------------------------

    public function testDeclareDispositionSendsTheDeclarationAndReadsTheWarnings(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, [
            'customerRef' => 'c-1', 'recorded' => true, 'status' => 'declared', 'appended' => true,
            'id' => 'd-2', 'disposition' => 'legal_hold', 'retentionUntil' => '2030-12-31', 'hasReason' => true,
            'endedAtSnapshot' => '2026-09-30T12:00:00Z', 'scheduleRef' => 'CC-12', 'basis' => null,
            'hasBasisNote' => false, 'declaredBy' => 'api:purge', 'declaredAt' => '2026-10-09T12:00:00Z',
            'agreelyIdentity' => 'retained',
            'warnings' => [['code' => 'hold_active', 'message' => 'A hold is in place.', 'holdIds' => [self::HOLD]]],
        ])]);
        $result = $this->client($http)->retention()->declareDisposition('c-1', [
            'disposition' => Disposition::LEGAL_HOLD,
            'reason' => 'Loi sur les impôts, art. 35',
            'retentionUntil' => '2030-12-31',
            'scheduleRef' => 'CC-12',
        ]);
        $this->assertSame('/v1/customers/c-1/retention/dispositions', $http->calls[0]->path());
        $this->assertSame([
            'disposition' => 'legal_hold', 'reason' => 'Loi sur les impôts, art. 35', 'retentionUntil' => '2030-12-31',
            'scheduleRef' => 'CC-12',
        ], $http->calls[0]->body);
        $this->assertSame('declared', $result->status);
        $this->assertTrue($result->appended);
        $this->assertSame('legal_hold', $result->declaration->disposition);
        $this->assertSame(AgreelyIdentity::RETAINED, $result->declaration->agreelyIdentity);
        $this->assertSame(DispositionWarning::HOLD_ACTIVE, $result->warnings[0]->code);
        $this->assertSame([self::HOLD], $result->warnings[0]->holdIds);
    }

    public function testDeclareDispositionRefusesTheSureRefusals(): void
    {
        $this->refused(fn (Agreely $a) => $a->retention()->declareDisposition('c', ['disposition' => 'kept']));
        $this->refused(fn (Agreely $a) => $a->retention()->declareDisposition('c', ['disposition' => 'legal_hold']));
        $this->refused(fn (Agreely $a) => $a->retention()->declareDisposition('c', ['disposition' => 'legal_hold', 'reason' => '  ']));
        $this->refused(fn (Agreely $a) => $a->retention()->declareDisposition('c', [
            'disposition' => 'destroyed', 'retentionUntil' => '2030-01-01',
        ]));
        $this->refused(fn (Agreely $a) => $a->retention()->declareDisposition('c', [
            'disposition' => 'legal_hold', 'reason' => 'Loi', 'retentionUntil' => '31/12/2030',
        ]));
        $this->refused(fn (Agreely $a) => $a->retention()->declareDisposition('c', [
            'disposition' => 'destroyed', 'reason' => str_repeat('r', 2001),
        ]));
        $this->refused(fn (Agreely $a) => $a->retention()->declareDisposition('c', [
            'disposition' => 'destroyed', 'scheduleRef' => str_repeat('s', 201),
        ]));
        $this->refused(fn (Agreely $a) => $a->retention()->declareDisposition('c', ['disposition' => 'destroyed', 'when' => 'now']));
    }

    public function testADispositionOnALiveRelationshipIsANamedConflict(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(409, ['error' => ['code' => 'relationship_active', 'message' => 'live']])]);
        try {
            $this->client($http)->retention()->declareDisposition('c', ['disposition' => 'destroyed']);
            $this->fail('expected AgreelyConflictError');
        } catch (AgreelyConflictError $e) {
            $this->assertSame(ErrorCode::RELATIONSHIP_ACTIVE, $e->code);
        }
    }

    // --- holds ------------------------------------------------------------------------------------------------------

    public function testPlaceHoldSendsTheHoldWithAnIdempotencyKey(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(201, ['customerRef' => 'c-1', 'recorded' => true]
            + self::apiHold() + ['replayed' => false])]);
        $placed = $this->client($http)->retention()->placeHold('c-1', [
            'ground' => RetentionHold::GROUND_OTHER_LAW,
            'provision' => 'Code civil du Québec, art. 2925',
            'scope' => ['rules' => [self::RULE]],
            'startedOn' => '2026-10-09',
            'reviewOn' => '2027-10-09',
        ], ['idempotencyKey' => 'hold-run-7']);
        $call = $http->calls[0];
        $this->assertSame('/v1/customers/c-1/retention/holds', $call->path());
        $this->assertSame('hold-run-7', $call->header('Idempotency-Key'));
        $this->assertSame([
            'ground' => 'other_law', 'provision' => 'Code civil du Québec, art. 2925', 'scope' => ['rules' => [self::RULE]],
            'startedOn' => '2026-10-09', 'reviewOn' => '2027-10-09',
        ], $call->body);
        $this->assertFalse($placed->replayed);
        $this->assertSame(self::HOLD, $placed->hold->id);
        $this->assertTrue($placed->hold->isActive());
    }

    public function testPlaceHoldDefaultsToAllAndGeneratesAKey(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['customerRef' => 'c-1', 'recorded' => true]
            + self::apiHold() + ['replayed' => true])]);
        $placed = $this->client($http)->retention()->placeHold('c-1', ['ground' => 'rights_request', 'scope' => 'all']);
        $this->assertSame(['ground' => 'rights_request', 'scope' => 'all'], $http->calls[0]->body);
        $this->assertStringStartsWith('idem_', (string) $http->calls[0]->header('Idempotency-Key'));
        $this->assertTrue($placed->replayed);
    }

    public function testPlaceHoldRefusesTheSureRefusals(): void
    {
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'litigation']));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'other_law']));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'rights_request', 'provision' => 'x']));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'rights_request', 'rightsRequestId' => 'r']));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'rights_request', 'scope' => []]));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'rights_request', 'scope' => ['rules' => []]]));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'rights_request', 'scope' => [self::RULE]]));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'rights_request', 'scope' => ['fields' => ['x']]]));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'rights_request', 'startedOn' => 'today']));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', [
            'ground' => 'other_law', 'provision' => str_repeat('p', 2001),
        ]));
        $this->refused(fn (Agreely $a) => $a->retention()->placeHold('c', ['ground' => 'rights_request'], ['idempotencyKey' => 'has space']));
    }

    public function testTheHoldBudgetIsADailyCap(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(429, ['error' => ['code' => 'hold_budget_exhausted', 'message' => 'cap']])]);
        $this->expectException(AgreelyDailyCapError::class);
        $this->client($http)->retention()->placeHold('c', ['ground' => 'rights_request']);
    }

    public function testReleaseHoldSendsTheReasonAndReadsWhatItDidToTheIdentity(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, ['customerRef' => 'c-1', 'recorded' => true]
            + self::apiHold('released') + ['agreelyIdentity' => 'erased'])]);
        $released = $this->client($http)->retention()->releaseHold('c-1', self::HOLD, ['reason' => 'Recours épuisés']);
        $this->assertSame('/v1/customers/c-1/retention/holds/' . self::HOLD . '/release', $http->calls[0]->path());
        $this->assertSame(['reason' => 'Recours épuisés'], $http->calls[0]->body);
        $this->assertNotNull($http->calls[0]->header('Idempotency-Key'));
        $this->assertFalse($released->hold->isActive());
        $this->assertSame(AgreelyIdentity::ERASED, $released->agreelyIdentity);
    }

    public function testReleaseHoldRequiresAReason(): void
    {
        $this->refused(fn (Agreely $a) => $a->retention()->releaseHold('c', self::HOLD, ['reason' => ' ']));
        /** @phpstan-ignore argument.type (the point is a shape the type forbids) */
        $this->refused(fn (Agreely $a) => $a->retention()->releaseHold('c', self::HOLD, []));
        $this->refused(fn (Agreely $a) => $a->retention()->releaseHold('c', '', ['reason' => 'r']));
        $this->refused(fn (Agreely $a) => $a->retention()->releaseHold('c', self::HOLD, ['reason' => 'r', 'by' => 'me']));
    }

    public function testAnAlreadyReleasedHoldAndTheReleaseCapAreTyped(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(409, ['error' => ['code' => 'already_released', 'message' => 'done']])]);
        try {
            $this->client($http)->retention()->releaseHold('c', self::HOLD, ['reason' => 'r']);
            $this->fail('expected AgreelyConflictError');
        } catch (AgreelyConflictError $e) {
            $this->assertSame(ErrorCode::ALREADY_RELEASED, $e->code);
        }

        $http = new MockHttpClient([MockHttpClient::json(429, ['error' => ['code' => 'hold_release_cap_reached', 'message' => 'cap']])]);
        try {
            $this->client($http)->retention()->releaseHold('c', self::HOLD, ['reason' => 'r']);
            $this->fail('expected AgreelyDailyCapError');
        } catch (AgreelyDailyCapError $e) {
            $this->assertSame(ErrorCode::HOLD_RELEASE_CAP_REACHED, $e->code);
            $this->assertCount(1, $http->calls);
        }
    }
}
