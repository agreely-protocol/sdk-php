<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 429 `verbal_daily_cap`: the organisation reached its daily limit of verbal
 * (telephone) consents.
 *
 * It is a per-company DAILY cap, not the per-minute rate window, so waiting a few
 * seconds does not lift it. A subclass of AgreelyDailyCapError (and so of
 * AgreelyRateLimitError), so a generic rate-limit catch still catches it. The SDK
 * NEVER auto-retries it, even when maxRetries is set: record the consent tomorrow,
 * or on paper.
 */
class AgreelyVerbalDailyCapError extends AgreelyDailyCapError
{
    public function __construct(
        string $message,
        string $code = 'verbal_daily_cap',
        ?int $status = 429,
        ?int $retryAfter = null,
        ?\Throwable $previous = null,
        ?string $reason = null,
    ) {
        parent::__construct($message, $code, $status, $retryAfter, $previous, $reason);
    }
}
