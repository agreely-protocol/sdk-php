<?php

declare(strict_types=1);

namespace Agreely\Sdk\Test\Unit;

use Agreely\Sdk\Agreely;
use Agreely\Sdk\Errors\AgreelyAuthError;
use Agreely\Sdk\Errors\AgreelyBillingInactiveError;
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\AgreelyDailyCapError;
use Agreely\Sdk\Errors\AgreelyError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Errors\AgreelyRateLimitError;
use Agreely\Sdk\Errors\AgreelyUnavailableError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Errors\AgreelyVerbalDailyCapError;
use Agreely\Sdk\Errors\ErrorCode;
use Agreely\Sdk\Errors\ErrorReason;
use Agreely\Sdk\Test\Support\MockHttpClient;
use PHPUnit\Framework\TestCase;

final class ErrorReasonTest extends TestCase
{
    private function client(MockHttpClient $http, int $maxRetries = 0): Agreely
    {
        return new Agreely([
            'apiKey' => 'k',
            'baseUrl' => 'https://api.test',
            'httpClient' => $http,
            'maxRetries' => $maxRetries,
            'respectRetryAfter' => false,
        ]);
    }

    /** @param array<string,string> $error */
    private static function refusal(int $status, array $error): MockHttpClient
    {
        return new MockHttpClient([MockHttpClient::json($status, ['error' => $error + ['message' => 'refused']])]);
    }

    private function catchFrom(MockHttpClient $http): AgreelyError
    {
        try {
            $this->client($http)->catalog()->list();
        } catch (AgreelyError $e) {
            return $e;
        }
        $this->fail('expected an AgreelyError');
    }

    public function testEveryStatusCarriesTheReasonAndTheFieldItWasSent(): void
    {
        $cases = [
            [422, AgreelyValidationError::class],
            [400, AgreelyValidationError::class],
            [404, AgreelyNotFoundError::class],
            [409, AgreelyConflictError::class],
            [403, AgreelyAuthError::class],
            [402, AgreelyBillingInactiveError::class],
        ];
        foreach ($cases as [$status, $class]) {
            $e = $this->catchFrom(self::refusal($status, [
                'code' => 'some_code', 'reason' => ErrorReason::PREDATES_WITHDRAWAL, 'field' => 'effectiveDate',
            ]));
            $this->assertInstanceOf($class, $e, "HTTP {$status}");
            $this->assertSame(ErrorReason::PREDATES_WITHDRAWAL, $e->reason, "HTTP {$status}");
            $this->assertTrue($e->hasReason(ErrorReason::PREDATES_WITHDRAWAL));
            $this->assertSame('some_code', $e->code);
            $this->assertSame($status, $e->status);
            if ($status !== 402) {
                $this->assertSame('effectiveDate', $e->field, "HTTP {$status}");
            }
        }
    }

    public function testAnUnknownFutureReasonIsReadableAsAStringAndNeverThrows(): void
    {
        $e = $this->catchFrom(self::refusal(422, ['code' => 'invalid_request', 'reason' => 'a_reason_added_in_2027']));
        $this->assertInstanceOf(AgreelyValidationError::class, $e);
        $this->assertSame('a_reason_added_in_2027', $e->reason);
        $this->assertFalse($e->hasReason(ErrorReason::NOT_REVOCABLE));
    }

    public function testAReasonOfTheWrongTypeIsDroppedRatherThanCoerced(): void
    {
        $http = new MockHttpClient([
            MockHttpClient::json(422, ['error' => ['code' => 'invalid_request', 'message' => 'x', 'reason' => ['nested']]]),
        ]);
        $e = $this->catchFrom($http);
        $this->assertNull($e->reason);
    }

    public function testNoReasonIsNull(): void
    {
        $e = $this->catchFrom(self::refusal(401, ['code' => 'unauthorized']));
        $this->assertNull($e->reason);
        $this->assertNull($e->field);
    }

    public function testANamedConflictCodeIsNeitherARetryNorAStateConflict(): void
    {
        $e = $this->catchFrom(self::refusal(409, ['code' => ErrorCode::IDENTITY_HELD]));
        $this->assertInstanceOf(AgreelyConflictError::class, $e);
        $this->assertSame(ErrorCode::IDENTITY_HELD, $e->code);
        $this->assertFalse($e->isRetryable());
        $this->assertFalse($e->isStateConflict());
    }

    public function testEachDailyCapIsItsOwnTypedRateLimit(): void
    {
        $cases = [
            ErrorCode::VERBAL_DAILY_CAP => AgreelyVerbalDailyCapError::class,
            ErrorCode::WITHDRAWAL_DAILY_CAP => AgreelyDailyCapError::class,
            ErrorCode::HOLD_BUDGET_EXHAUSTED => AgreelyDailyCapError::class,
            ErrorCode::HOLD_RELEASE_CAP_REACHED => AgreelyDailyCapError::class,
        ];
        foreach ($cases as $code => $class) {
            $e = $this->catchFrom(self::refusal(429, ['code' => $code, 'reason' => ErrorReason::DAILY_CAP]));
            $this->assertInstanceOf($class, $e, $code);
            $this->assertInstanceOf(AgreelyDailyCapError::class, $e, $code);
            $this->assertInstanceOf(AgreelyRateLimitError::class, $e, $code);
            $this->assertSame($code, $e->code);
        }
    }

    public function testAnUnknown429CodeIsKeptAsSent(): void
    {
        $e = $this->catchFrom(self::refusal(429, ['code' => 'a_new_cap']));
        $this->assertInstanceOf(AgreelyRateLimitError::class, $e);
        $this->assertSame('a_new_cap', $e->code);
    }

    public function testOnlyThePerMinuteWindowIsEverAutoRetried(): void
    {
        foreach ([ErrorCode::HOLD_RELEASE_CAP_REACHED, 'a_new_cap'] as $code) {
            $http = new MockHttpClient([
                MockHttpClient::json(429, ['error' => ['code' => $code, 'message' => 'cap']], ['Retry-After' => '0']),
                MockHttpClient::json(200, ['catalog' => []]),
            ]);
            try {
                $this->client($http, 3)->catalog()->list();
                $this->fail("expected a throw for {$code}");
            } catch (AgreelyRateLimitError) {
                $this->assertCount(1, $http->calls, "{$code} is never auto-retried");
            }
        }

        $http = new MockHttpClient([
            MockHttpClient::json(429, ['error' => ['code' => 'rate_limited', 'message' => 'window']], ['Retry-After' => '0']),
            MockHttpClient::json(200, ['catalog' => []]),
        ]);
        $this->assertSame([], $this->client($http, 1)->catalog()->list());
        $this->assertCount(2, $http->calls, 'the minute window is retried on a read when maxRetries allows');
    }

    public function testAny429WhoseReasonIsDailyCapIsADailyCapKeepingItsCode(): void
    {
        $e = $this->catchFrom(self::refusal(429, ['code' => 'a_new_daily_cap', 'reason' => ErrorReason::DAILY_CAP]));
        $this->assertInstanceOf(AgreelyDailyCapError::class, $e);
        $this->assertSame('a_new_daily_cap', $e->code);
    }

    public function testAnUnknown429IsThePlainRateLimitErrorNotADailyCap(): void
    {
        $e = $this->catchFrom(self::refusal(429, ['code' => 'a_new_cap', 'reason' => 'something_else']));
        $this->assertSame(AgreelyRateLimitError::class, $e::class);
    }

    public function testA5xxAndAnUnmappedStatusKeepTheEnvelope(): void
    {
        foreach ([500, 405, 410] as $status) {
            $e = $this->catchFrom(self::refusal($status, ['code' => 'not_recorded', 'reason' => 'a_reason', 'field' => 'f']));
            $this->assertInstanceOf(AgreelyUnavailableError::class, $e, "HTTP {$status}");
            $this->assertSame('not_recorded', $e->code, "HTTP {$status}");
            $this->assertSame('a_reason', $e->reason);
            $this->assertSame('f', $e->field);
            $this->assertFalse($e->retryable);
        }
        $e = $this->catchFrom(new MockHttpClient([new \Agreely\Sdk\Http\RawResponse(502, '<html>bad gateway</html>')]));
        $this->assertInstanceOf(AgreelyUnavailableError::class, $e);
        $this->assertSame('unavailable', $e->code, 'no envelope: the generic code');
    }

    public function testADailyCapReasonIsNeverAutoRetriedWhateverItsCode(): void
    {
        foreach ([['code' => 'rate_limited', 'reason' => 'daily_cap'], ['reason' => 'daily_cap']] as $error) {
            $http = new MockHttpClient([
                MockHttpClient::json(429, ['error' => $error + ['message' => 'cap']], ['Retry-After' => '0']),
                MockHttpClient::json(200, ['catalog' => []]),
            ]);
            try {
                $this->client($http, 2)->catalog()->list();
                $this->fail('expected AgreelyDailyCapError for ' . json_encode($error));
            } catch (AgreelyDailyCapError $e) {
                $this->assertSame('rate_limited', $e->code);
                $this->assertCount(1, $http->calls, 'an idempotent GET with maxRetries 2 is still sent once');
            }
        }
    }
}
