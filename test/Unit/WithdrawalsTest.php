<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Errors\AgreelyDailyCapError;
use Agreely\Sdk\Errors\ErrorReason;
use Agreely\Sdk\Test\Support\MockHttpClient;
use Agreely\Sdk\Types\ConsentWithdrawal;
use PHPUnit\Framework\TestCase;

final class WithdrawalsTest extends TestCase
{
    private const REF = '0x9f2c4e0a7b1d3c5e8f6a2b4c6d8e0f1a3b5c7d9e1f2a4b6c8d0e2f4a6b8c0d2e';
    private const ALSO = '0x31036982c07fa3bd7f03281ec1a8a481973fbd71654bd066cf4c7b8a59a4c354';

    private function client(MockHttpClient $http): Agreely
    {
        return new Agreely(['apiKey' => 'k', 'baseUrl' => 'https://api.test', 'httpClient' => $http, 'maxRetries' => 3]);
    }

    private static function recorded(string $gate = 'denied'): MockHttpClient
    {
        return new MockHttpClient([MockHttpClient::json(200, [
            'consentRef' => self::REF, 'withdrawn' => true, 'alreadyWithdrawn' => $gate === 'unchanged',
            'recordedOnBehalf' => true, 'assurance' => 'company_attested', 'gate' => $gate,
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
        $this->assertSame('company_attested', $result->assurance);
        $this->assertTrue($result->gateDenied());
        $this->assertSame([self::ALSO], $result->alsoWithdrawn);
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

    public function testTheDailyCapIsTypedAndNeverAutoRetried(): void
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
        $this->assertCount(1, $http->calls);
    }
}
