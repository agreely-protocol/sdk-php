<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 429. `code` "rate_limited" is the per-company request window (the only 429 the
 * SDK may auto-retry, and only on an idempotent read when maxRetries is set). Every
 * other 429 code is a cap that waiting seconds does not lift, and has its own
 * subclass: {@see AgreelySweepTooFrequentError}, {@see AgreelyDailyCapError}.
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
