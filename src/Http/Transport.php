<?php

declare(strict_types=1);

namespace Agreely\Sdk\Http;

use Agreely\Sdk\Errors\AgreelyAuthError;
use Agreely\Sdk\Errors\AgreelyBillingInactiveError;
use Agreely\Sdk\Errors\AgreelyConflictError;
use Agreely\Sdk\Errors\AgreelyDailyCapError;
use Agreely\Sdk\Errors\AgreelyError;
use Agreely\Sdk\Errors\AgreelyNotFoundError;
use Agreely\Sdk\Errors\AgreelyRateLimitError;
use Agreely\Sdk\Errors\AgreelySweepTooFrequentError;
use Agreely\Sdk\Errors\AgreelyUnavailableError;
use Agreely\Sdk\Errors\AgreelyValidationError;
use Agreely\Sdk\Errors\AgreelyVerbalDailyCapError;
use Agreely\Sdk\Errors\AgreelyWithdrawalDailyCapError;
use Agreely\Sdk\Errors\ErrorCode;

/**
 * The thin HTTP layer, ported from the TS transport.ts: build the request,
 * enforce a SINGLE total time budget across attempts, map every response/failure
 * to a typed error, and retry ONLY idempotent calls on a transient outage
 * (network error or 503), capped at two attempts with jittered backoff, all
 * inside the budget.
 */
final class Transport
{
    private readonly string $baseUrl;

    public function __construct(
        string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutMs,
        private readonly HttpClient $http,
        private readonly int $maxRetries = 0,
        private readonly bool $respectRetryAfter = true,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** The client's total time budget per call, in milliseconds. */
    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }

    /**
     * Send a request and decode its JSON body. On a 429 for an IDEMPOTENT call, opt-in
     * retry up to `maxRetries` times, honoring Retry-After (outside the per-attempt
     * time budget). A mutating call never auto-retries a 429.
     *
     * ONLY the per-company window (code "rate_limited") is ever auto-retried. Every
     * other 429 is a cap that waiting seconds does not lift, whatever maxRetries says:
     * AgreelySweepTooFrequentError (the pass being declared is already represented by
     * the one declared less than 15 minutes ago, so sending it again records a second
     * pass for the same run) and every AgreelyDailyCapError (a rolling 24-hour cap).
     *
     * @return array<string,mixed> the decoded JSON object
     */
    public function request(RequestSpec $spec): array
    {
        return self::decode($this->send($spec));
    }

    /**
     * As {@see request()}, with the 2xx status beside the decoded body, for a route whose
     * 200 and 201 mean different things (an upsert that created or merged).
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    public function requestWithStatus(RequestSpec $spec): array
    {
        $res = $this->send($spec);
        return ['status' => $res->status, 'body' => self::decode($res)];
    }

    /**
     * Send a request and return the raw 2xx response untouched: the body as bytes and
     * the headers, for a route that answers something other than JSON (a PDF). Every
     * non-2xx answer still maps to the same typed error as {@see request()}, read from
     * its JSON error envelope.
     */
    public function requestRaw(RequestSpec $spec): RawResponse
    {
        return $this->send($spec);
    }

    /**
     * A 2xx body as a JSON object; an empty or non-object body is an empty one.
     *
     * @return array<string,mixed>
     */
    private static function decode(RawResponse $res): array
    {
        if ($res->body === '') {
            return [];
        }
        $decoded = json_decode($res->body, true);
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /** The 429 retry loop around one budgeted attempt. */
    private function send(RequestSpec $spec): RawResponse
    {
        $rateRetries = $spec->idempotentRetry ? $this->maxRetries : 0;
        $rateAttempt = 0;
        while (true) {
            try {
                return $this->attempt($spec);
            } catch (AgreelyRateLimitError $error) {
                if ($error->errorCode() !== ErrorCode::RATE_LIMITED || $rateAttempt >= $rateRetries) {
                    throw $error;
                }
                $rateAttempt++;
                $waitMs = ($this->respectRetryAfter && $error->retryAfter !== null)
                    ? $error->retryAfter * 1000
                    : $this->jitterBackoff($rateAttempt);
                usleep($waitMs * 1000);
            }
        }
    }

    /**
     * One budgeted send with transient-outage retries inside a single time budget.
     */
    private function attempt(RequestSpec $spec): RawResponse
    {
        $url = $this->buildUrl($spec->path, $spec->query);
        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ], $spec->headers);

        $bodyText = null;
        if ($spec->body !== null) {
            $headers['Content-Type'] = 'application/json';
            $bodyText = (string) json_encode($spec->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $deadlineMs = $this->nowMs() + ($spec->timeoutMs ?? $this->timeoutMs);
        $maxAttempts = $spec->idempotentRetry ? 2 : 1;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $remaining = $deadlineMs - $this->nowMs();
            if ($remaining <= 0) {
                break;
            }

            try {
                $res = $this->http->send($spec->method, $url, $headers, $bodyText, (int) $remaining);
                $this->assertSuccess($res);
                return $res;
            } catch (TransportException $raw) {
                $lastError = $this->normalize($raw);
            } catch (AgreelyError $typed) {
                // assertSuccess() throws typed errors; only AgreelyUnavailableError is retryable.
                $lastError = $typed;
            }

            $transient = $lastError instanceof AgreelyUnavailableError && $lastError->retryable;
            $canRetry = $spec->idempotentRetry && $transient && $attempt < $maxAttempts;
            if (!$canRetry) {
                throw $lastError;
            }

            $backoff = $this->jitterBackoff($attempt);
            if ($this->nowMs() + $backoff >= $deadlineMs) {
                throw $lastError;
            }
            usleep($backoff * 1000);
        }

        throw $lastError ?? new AgreelyUnavailableError(
            'Agreely was unreachable within the time budget.',
            null,
            true,
        );
    }

    /**
     * @param array<string,string|null> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $url = $this->baseUrl . $path;
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value !== null && $value !== '') {
                $pairs[$key] = $value;
            }
        }
        if ($pairs !== []) {
            $url .= '?' . http_build_query($pairs);
        }
        return $url;
    }

    /**
     * Return on a 2xx, or throw the typed error for the status. Every error carries the
     * envelope's `code`, `reason` and `field` as the server sent them.
     */
    private function assertSuccess(RawResponse $res): void
    {
        if ($res->status >= 200 && $res->status < 300) {
            return;
        }

        $wire = $this->safeErrorEnvelope($res->body);
        $message = $wire['message'] ?? "Agreely request failed (HTTP {$res->status}).";
        $code = $wire['code'] ?? null;
        $field = $wire['field'] ?? null;
        $reason = $wire['reason'] ?? null;

        switch ($res->status) {
            case 401:
            case 403:
                throw new AgreelyAuthError($message, $code ?? 'unauthorized', $res->status, $field, null, $reason);
            case 400:
            case 422:
                throw new AgreelyValidationError($message, $code ?? 'invalid_request', $res->status, $field, null, $reason);
            case 404:
                throw new AgreelyNotFoundError($message, $code ?? 'not_found', $res->status, $field, null, $reason);
            case 409:
                // `retry`: a concurrent retry of one declaration could not be settled, retry
                // with the SAME Idempotency-Key. `conflict` and the named codes: the request
                // contradicts the record's state, no retry helps; `reason` says which state.
                throw new AgreelyConflictError($message, $code ?? 'retry', 409, null, $reason, $field);
            case 413:
                // An over-large body. A VALIDATION failure, not an outage: the default 5xx
                // branch below would have made a caller treat it as transient and retry a
                // body that can never be accepted.
                throw new AgreelyValidationError($message, $code ?? 'body_too_large', 413, $field, null, $reason);
            case 429:
                $header = $res->header('Retry-After');
                $retryAfter = ($header !== null && is_numeric($header)) ? (int) $header : null;
                switch ($code) {
                    case ErrorCode::SWEEP_TOO_FREQUENT:
                        // The per-(rule, hostSystem) 15-minute floor, not the company window.
                        throw new AgreelySweepTooFrequentError($message, ErrorCode::SWEEP_TOO_FREQUENT, 429, $retryAfter, null, $reason);
                    case ErrorCode::VERBAL_DAILY_CAP:
                        // The organisation's daily limit of verbal consents, not the minute window.
                        throw new AgreelyVerbalDailyCapError($message, ErrorCode::VERBAL_DAILY_CAP, 429, $retryAfter, null, $reason);
                    case ErrorCode::WITHDRAWAL_DAILY_CAP:
                        throw new AgreelyWithdrawalDailyCapError($message, ErrorCode::WITHDRAWAL_DAILY_CAP, 429, $retryAfter, null, $reason);
                    case ErrorCode::HOLD_BUDGET_EXHAUSTED:
                    case ErrorCode::HOLD_RELEASE_CAP_REACHED:
                        throw new AgreelyDailyCapError($message, (string) $code, 429, $retryAfter, null, $reason);
                }
                // The per-company window, or a 429 whose code this client does not know yet:
                // its code is kept as sent, and only "rate_limited" is ever auto-retried.
                throw new AgreelyRateLimitError($message, $code ?? 'rate_limited', 429, $retryAfter, null, $reason);
            case 402:
                // The company's Agreely subscription is inactive/lapsed. Fail-closed for
                // gating, but distinct from an outage: not transient, not retryable.
                throw new AgreelyBillingInactiveError($message, $code ?? 'billing_inactive', 402, null, $reason);
            default:
                // 503 and any other 5xx: unreachable. 503 is retryable for idempotent calls.
                throw new AgreelyUnavailableError($message, $res->status, $res->status === 503);
        }
    }

    /**
     * @return array{message?:string,code?:string,field?:string,reason?:string}
     */
    private function safeErrorEnvelope(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return [];
        }
        /** @var array<string,mixed> $err */
        $err = $decoded['error'];
        $out = [];
        if (isset($err['message']) && is_string($err['message'])) {
            $out['message'] = $err['message'];
        }
        if (isset($err['code']) && is_string($err['code'])) {
            $out['code'] = $err['code'];
        }
        if (isset($err['field']) && is_string($err['field'])) {
            $out['field'] = $err['field'];
        }
        if (isset($err['reason']) && is_string($err['reason'])) {
            $out['reason'] = $err['reason'];
        }
        return $out;
    }

    /** Turn a transport failure into a typed unavailable error (aborts/network -> unavailable, retryable). */
    private function normalize(TransportException $raw): AgreelyUnavailableError
    {
        return new AgreelyUnavailableError($raw->getMessage(), null, true, $raw);
    }

    /**
     * A small jittered backoff for retry attempt N (1-based), in milliseconds.
     * Full jitter over a tiny base so two retries stay well within an ~800ms budget.
     */
    private function jitterBackoff(int $attempt): int
    {
        $base = 25 * $attempt;
        return random_int(0, max(0, $base - 1));
    }

    private function nowMs(): float
    {
        return microtime(true) * 1000;
    }
}
