<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 429 `withdrawal_daily_cap` (reason `daily_cap`): the organisation recorded as many
 * withdrawals over /v1 as it may in any rolling 24 hours (50 unless the operator set
 * another value). No Retry-After: it is a tripwire for a stolen or runaway key, not a
 * throttle to wait out, and the workspace owner is emailed on the first refusal of
 * the day.
 *
 * The person's withdrawal still has to be honoured: record it from the customer's
 * record in Agreely. A repeat on an already withdrawn consent is never refused by the
 * cap. The SDK NEVER auto-retries it.
 */
class AgreelyWithdrawalDailyCapError extends AgreelyDailyCapError
{
    public function __construct(
        string $message,
        string $code = 'withdrawal_daily_cap',
        ?int $status = 429,
        ?int $retryAfter = null,
        ?\Throwable $previous = null,
        ?string $reason = null,
    ) {
        parent::__construct($message, $code, $status, $retryAfter, $previous, $reason);
    }
}
