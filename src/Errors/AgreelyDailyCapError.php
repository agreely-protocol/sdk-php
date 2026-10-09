<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 429 on one of the organisation's ROLLING 24-HOUR caps, which are tripwires for a
 * stolen or runaway key rather than throttles: waiting a few seconds does not lift
 * them, and most carry no Retry-After. `code` says which one ({@see ErrorCode}):
 *
 *   verbal_daily_cap          telephone consents recorded            ({@see AgreelyVerbalDailyCapError})
 *   withdrawal_daily_cap      withdrawals recorded over /v1 (reason daily_cap, no
 *                             Retry-After: record further ones from the customer record)
 *   hold_budget_exhausted     retention holds placed over /v1
 *   hold_release_cap_reached  holds your system did not place, released over /v1
 *
 * Any other 429 whose `reason` is `daily_cap` raises it too, keeping its code.
 *
 * Past the cap, the organisation's own people act in Agreely itself (the customer
 * record). A subclass of AgreelyRateLimitError, so a generic rate-limit catch still
 * catches it. The SDK NEVER auto-retries it, even when maxRetries is set.
 */
class AgreelyDailyCapError extends AgreelyRateLimitError
{
}
