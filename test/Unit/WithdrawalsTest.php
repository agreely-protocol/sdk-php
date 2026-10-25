<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Errors\AgreelyDailyCapError;
use Agreely\Sdk\Errors\AgreelyOtherConsentsRunningError;
use Agreely\Sdk\Errors\ErrorReason;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\ConsentWithdrawal;
use PHPUnit\Framework\TestCase;

final class WithdrawalsTest extends TestCase
{
    private const REF = '0x9f2c4e0a7b1d3c5e8f6a2b4c6d8e0f1a3b5c7d9e1f2a4b6c8d0e2f4a6b8c0d2e';
    private const ALSO = '0x31036982c07fa3bd7f03281ec1a8a481973fbd71654bd066cf4c7b8a59a4c354';
    private const OTHER = '0x6b1e0d3f5a7c9e2b4d6f8a0c1e3b5d7f9a2c4e6b8d0f1a3c5e7b9d2f4a6c8e0b';

    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http, 'maxRetries' => 3]);
    }

    private static function recorded(string $gate = 'denied'): MockHttpClient
    {
        return new MockHttpClient([MockHttpClient::json(200, [
            'consentRef' => self::REF, 'withdrawn' => true, 'alreadyWithdrawn' => $gate === 'unchanged',
            'recordedOnBehalf' => true, 'assurance' => 'company_documented', 'gate' => $gate,
            'alsoWithdrawn' => $gate === 'unchanged' ? [] : [self::ALSO],
        ])]);
    }

    public function testRecordSendsTheDeclarationToTheCustomersConsent(): void
    {
        $http = self::recorded();
        $result = $this->client($http)->withdrawals()->record('STORE/0042', self::REF, [
            'channel' => 'phone',
            'operator' => 'intervenant-0042',
            'requestedAt' => '2026-10-08T09:15:00-04:00',
            'reason' => "Appel du 8 octobre, ne souhaite plus recevoir l'infolettre.",
        ]);
        $call = $http->calls[0];
        $this->assertSame('POST', $call->method);
        $this->assertSame(
            '/v1/customers/STORE%2F0042/consents/' . self::REF . '/withdrawal',
            parse_url($call->url, PHP_URL_PATH),
        );
        $this->assertSame([
            'channel' => 'phone',
            'operator' => 'intervenant-0042',
            'requestedAt' => '2026-10-08T09:15:00-04:00',
            'reason' => "Appel du 8 octobre, ne souhaite plus recevoir l'infolettre.",
        ], $call->body);
        $this->assertStringStartsWith('idem_', (string) $call->header('Idempotency-Key'));

        $this->assertInstanceOf(ConsentWithdrawal::class, $result);
        $this->assertTrue($result->withdrawn);
        $this->assertTrue($result->recordedOnBehalf);
        $this->assertSame('company_documented', $result->assurance);
        $this->assertTrue($result->gateDenied());
        $this->assertSame([self::ALSO], $result->alsoWithdrawn);
        $this->assertSame([], $result->stillInForce);
    }

    public function testTheScopeIsSentAsDeclaredAndNeverGuessed(): void
    {
        foreach (['keep', 'withdraw'] as $scope) {
            $http = self::recorded();
            $this->client($http)->withdrawals()->record('c-1', self::REF, [
                'channel' => 'phone', 'operator' => 'a', 'otherConsents' => $scope,
            ]);
            $this->assertSame(['channel' => 'phone', 'operator' => 'a', 'otherConsents' => $scope], $http->calls[0]->body);
        }

        $http = self::recorded();
        $this->client($http)->withdrawals()->record('c-1', self::REF, [
            'channel' => 'phone', 'operator' => 'a', 'alsoWithdraw' => [' ' . self::ALSO, self::OTHER],
        ]);
        $this->assertSame(
            ['channel' => 'phone', 'operator' => 'a', 'alsoWithdraw' => [self::ALSO, self::OTHER]],
            $http->calls[0]->body,
        );

        $http = self::recorded();
        $this->client($http)->withdrawals()->record('c-1', self::REF, [
            'channel' => 'phone', 'operator' => 'a', 'alsoWithdraw' => [],
        ]);
        $this->assertSame(['channel' => 'phone', 'operator' => 'a', 'alsoWithdraw' => []], $http->calls[0]->body);
    }

    public function testAnAlsoWithdrawOfFiftyIsTheMostAndFiftyOneIsRefused(): void
    {
        $http = self::recorded();
        $this->client($http)->withdrawals()->record('c-1', self::REF, [
            'channel' => 'phone', 'operator' => 'a', 'alsoWithdraw' => array_fill(0, 50, self::ALSO),
        ]);
        $sent = $http->calls[0]->body['alsoWithdraw'] ?? null;
        $this->assertIsArray($sent);
        $this->assertCount(50, $sent);

        $http = self::recorded();
        try {
            $this->client($http)->withdrawals()->record('c-1', self::REF, [
                'channel' => 'phone', 'operator' => 'a', 'alsoWithdraw' => array_fill(0, 51, self::ALSO),
            ]);
            $this->fail('expected AgreelyConfigError for 51 refs');
        } catch (AgreelyConfigError) {
            $this->assertCount(0, $http->calls);
        }
    }

    public function testAMalformedScopeIsRefusedBeforeAnyWireCall(): void
    {
        $bad = [
            ['otherConsents' => 'keep', 'alsoWithdraw' => []],
            ['otherConsents' => 'withdraw', 'alsoWithdraw' => [self::ALSO]],
            ['otherConsents' => 'KEEP'],
            ['otherConsents' => true],
            ['otherConsents' => ['keep']],
            ['otherConsents' => ''],
            ['alsoWithdraw' => self::ALSO],
            ['alsoWithdraw' => ['order-4471']],
            ['alsoWithdraw' => ['0x1234']],
            ['alsoWithdraw' => [42]],
            ['alsoWithdraw' => ['first' => self::ALSO]],
        ];
        foreach ($bad as $scope) {
            $http = self::recorded();
            try {
                /** @phpstan-ignore argument.type (the point is a shape the type forbids) */
                $this->client($http)->withdrawals()->record('c-1', self::REF, ['channel' => 'phone', 'operator' => 'a'] + $scope);
                $this->fail('expected AgreelyConfigError for ' . json_encode($scope));
            } catch (AgreelyConfigError) {
                $this->assertCount(0, $http->calls);
            }
        }
    }

    public function testOtherConsentsRunningCarriesEveryRefSoTheCallerCanAsk(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(422, [
            'error' => [
                'code' => 'invalid_request',
                'message' => 'Other consents are in force for this purpose.',
                'field' => 'otherConsents',
                'reason' => 'other_consents_running',
                'otherConsentRefs' => [self::ALSO, self::OTHER],
            ],
        ])]);
        try {
            $this->client($http)->withdrawals()->record('c-1', self::REF, ['channel' => 'phone', 'operator' => 'a']);
            $this->fail('expected AgreelyOtherConsentsRunningError');
        } catch (AgreelyOtherConsentsRunningError $e) {
            $this->assertInstanceOf(AgreelyValidationError::class, $e);
            $this->assertSame([self::ALSO, self::OTHER], $e->otherConsentRefs);
            $this->assertTrue($e->hasReason(ErrorReason::OTHER_CONSENTS_RUNNING));
            $this->assertSame('otherConsents', $e->field);
            $this->assertSame(422, $e->status);
            $this->assertSame('invalid_request', $e->code);
        }
    }

    public function testAnOtherRefusalOfTheScopeIsAPlainValidationErrorWithNoRefs(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(422, [
            'error' => ['code' => 'invalid_request', 'message' => 'not in force', 'field' => 'alsoWithdraw', 'reason' => 'not_running_for_purpose'],
        ])]);
        try {
            $this->client($http)->withdrawals()->record('c-1', self::REF, [
                'channel' => 'phone', 'operator' => 'a', 'alsoWithdraw' => [self::OTHER],
            ]);
            $this->fail('expected AgreelyValidationError');
        } catch (AgreelyOtherConsentsRunningError $e) {
            $this->fail('not_running_for_purpose is not other_consents_running: ' . $e->reason);
        } catch (AgreelyValidationError $e) {
            $this->assertTrue($e->hasReason(ErrorReason::NOT_RUNNING_FOR_PURPOSE));
            $this->assertSame('alsoWithdraw', $e->field);
        }
    }

    public function testStillInForceListsTheOtherConsentsTheActDidNotReach(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'consentRef' => self::REF, 'withdrawn' => true, 'alreadyWithdrawn' => false,
            'recordedOnBehalf' => true, 'assurance' => 'company_documented', 'gate' => 'superseded',
            'alsoWithdrawn' => [], 'stillInForce' => [self::OTHER],
        ])]);
        $result = $this->client($http)->withdrawals()->record('c-1', self::REF, [
            'channel' => 'phone', 'operator' => 'a', 'otherConsents' => 'keep',
        ]);
        $this->assertSame([self::OTHER], $result->stillInForce);
        $this->assertSame(ConsentWithdrawal::GATE_SUPERSEDED, $result->gate);
    }

    public function testAnAbsentAssuranceIsTheCompanyDocumentedTwinNotAttested(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(200, [
            'consentRef' => self::REF, 'withdrawn' => true, 'alreadyWithdrawn' => false, 'gate' => 'denied',
        ])]);
        $result = $this->client($http)->withdrawals()->record('c-1', self::REF, ['channel' => 'phone', 'operator' => 'a']);
        $this->assertSame('company_documented', $result->assurance);
    }

    public function testOnlyTheTwoRequiredMembersAreSentWhenTheRestIsAbsent(): void
    {
        $http = self::recorded('unchanged');
        $result = $this->client($http)->withdrawals()->record('c-1', self::REF, [
            'channel' => 'in_person',
            'operator' => 'desk:7',
        ], ['idempotencyKey' => 'withdraw-c-1-2026-10-09']);
        $this->assertSame(['channel' => 'in_person', 'operator' => 'desk:7'], $http->calls[0]->body);
        $this->assertSame('withdraw-c-1-2026-10-09', $http->calls[0]->header('Idempotency-Key'));
        $this->assertTrue($result->alreadyWithdrawn);
        $this->assertSame(ConsentWithdrawal::GATE_UNCHANGED, $result->gate);
        $this->assertSame([], $result->alsoWithdrawn);
    }

    public function testADateTimeRequestedAtIsSentInUtc(): void
    {
        $http = self::recorded();
        $this->client($http)->withdrawals()->record('c-1', self::REF, [
            'channel' => 'email',
            'operator' => 'agent-12',
            'requestedAt' => new \DateTimeImmutable('2026-10-08T09:15:00-04:00'),
        ]);
        $body = $http->calls[0]->body;
        $this->assertNotNull($body);
        $this->assertSame('2026-10-08T13:15:00.000Z', $body['requestedAt']);
    }

    public function testTheSureRefusalsNeverLeaveTheProcess(): void
    {
        $bad = [
            ['operator' => 'agent-12'],
            ['channel' => 'fax', 'operator' => 'agent-12'],
            ['channel' => 'phone'],
            ['channel' => 'phone', 'operator' => 'julie@example.com'],
            ['channel' => 'phone', 'operator' => 'Julie Tremblay'],
            ['channel' => 'phone', 'operator' => str_repeat('a', 65)],
            ['channel' => 'phone', 'operator' => 'agent-12', 'requestedAt' => '2026-10-08 09:15:00'],
            ['channel' => 'phone', 'operator' => 'agent-12', 'reason' => str_repeat('r', 1001)],
            ['channel' => 'phone', 'operator' => 'agent-12', 'reason' => "line\nbreak"],
            ['channel' => 'phone', 'operator' => 'agent-12', 'customerId' => 'smuggled'],
            ['channel' => 'phone', 'operator' => 'agent-12', 'idempotencyKey' => 'in-the-body'],
        ];
        $http = self::recorded();
        try {
            $this->client($http)->withdrawals()->record('c-1', self::REF, ['channel' => 'phone', 'operator' => 'a'], ['idempotency' => 'k']);
            $this->fail('a misspelt idempotencyKey option must not silently generate a random key');
        } catch (AgreelyConfigError) {
            $this->assertCount(0, $http->calls);
        }
        foreach ($bad as $input) {
            $http = self::recorded();
            try {
                /** @phpstan-ignore argument.type (the point is a shape the type forbids) */
                $this->client($http)->withdrawals()->record('c-1', self::REF, $input);
                $this->fail('expected AgreelyConfigError for ' . json_encode($input));
            } catch (AgreelyConfigError) {
                $this->assertCount(0, $http->calls);
            }
        }
        foreach ([['', self::REF], ['c-1', ' '], ['c-1', '0x1234'], ['c-1', 'order-4471']] as [$customer, $consent]) {
            $http = self::recorded();
            try {
                $this->client($http)->withdrawals()->record($customer, $consent, ['channel' => 'phone', 'operator' => 'a']);
                $this->fail('expected AgreelyConfigError');
            } catch (AgreelyConfigError) {
                $this->assertCount(0, $http->calls);
            }
        }
    }

    public function testAnUnresolvedReferenceIsOneNotFoundWithItsReason(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(404, [
            'error' => ['code' => 'not_found', 'message' => 'No such consent.', 'reason' => 'unknown_consent'],
        ])]);
        try {
            $this->client($http)->withdrawals()->record('c-1', self::REF, ['channel' => 'phone', 'operator' => 'a']);
            $this->fail('expected AgreelyNotFoundError');
        } catch (AgreelyNotFoundError $e) {
            $this->assertSame(ErrorReason::UNKNOWN_CONSENT, $e->reason);
        }
    }

    public function testALapsedConsentAndANonAskAreTypedByReason(): void
    {
        $http = new MockHttpClient([MockHttpClient::json(409, [
            'error' => ['code' => 'conflict', 'message' => 'lapsed', 'reason' => 'consent_lapsed'],
        ])]);
        try {
            $this->client($http)->withdrawals()->record('c-1', self::REF, ['channel' => 'phone', 'operator' => 'a']);
            $this->fail('expected AgreelyConflictError');
        } catch (AgreelyConflictError $e) {
            $this->assertTrue($e->isStateConflict());
            $this->assertTrue($e->hasReason(ErrorReason::CONSENT_LAPSED));
        }

        $http = new MockHttpClient([MockHttpClient::json(422, [
            'error' => ['code' => 'invalid_request', 'message' => 'not an ask', 'reason' => 'not_revocable'],
        ])]);
        try {
            $this->client($http)->withdrawals()->record('c-1', self::REF, ['channel' => 'phone', 'operator' => 'a']);
            $this->fail('expected AgreelyValidationError');
        } catch (AgreelyValidationError $e) {
            $this->assertSame(ErrorReason::NOT_REVOCABLE, $e->reason);
        }
    }

    public function testTheDailyCapIsADailyCapCarryingItsCodeAndReason(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(429, ['error' => [
                'code' => 'withdrawal_daily_cap', 'message' => 'cap', 'reason' => 'daily_cap',
            ]]),
            MockHttpClient::json(200, []),
        ]);
        try {
            $this->client($http)->withdrawals()->record('c-1', self::REF, ['channel' => 'phone', 'operator' => 'a']);
            $this->fail('expected AgreelyDailyCapError');
        } catch (AgreelyDailyCapError $e) {
            $this->assertSame('withdrawal_daily_cap', $e->code);
            $this->assertSame(ErrorReason::DAILY_CAP, $e->reason);
            $this->assertNull($e->retryAfter);
        }
    }
}
