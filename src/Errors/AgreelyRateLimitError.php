<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 429. THE RULE (the same in both SDKs):
 *
 *   - `rate_limited`, the per-company request window, is the ONLY code ever
 *     auto-retried, and only on an idempotent read when maxRetries is set.
 *   - `sweep_too_frequent` raises {@see AgreelySweepTooFrequentError}.
 *   - a known daily-cap code ({@see ErrorCode::DAILY_CAPS}), or any 429 whose reason
 *     is `daily_cap`, raises {@see AgreelyDailyCapError} keeping its code.
 *   - any OTHER code raises this base class, keeping the code it was sent with, and is
 *     never retried.
 */
class AgreelyRateLimitError extends AgreelyError
{
    /** Seconds until the window resets (from the Retry-After header), when given. */
    public readonly ?int $retryAfter;
    /** Alias of {@see $retryAfter} with an explicit unit in the name. */
    public readonly ?int $retryAfterSeconds;

    public function __construct(
        string $message,
        string $code = 'rate_limited',
        ?int $status = null,
        ?int $retryAfter = null,
        ?\Throwable $previous = null,
        ?string $reason = null,
    ) {
        parent::__construct($message, $code, $status, null, $previous, $reason);
        $this->retryAfter = $retryAfter;
        $this->retryAfterSeconds = $retryAfter;
    }
}
