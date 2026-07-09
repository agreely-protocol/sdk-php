<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 402 — the company behind the API key has an inactive/lapsed subscription
 * (trial ended unpaid, past_due, or canceled). DISTINCT from an outage: this is
 * NOT transient and NOT retryable. Treat it as fail-closed for gating (do NOT
 * grant a gated value) but surface it as actionable — the company must pay to
 * restore service, it is not "Agreely is down".
 */
class AgreelyBillingInactiveError extends AgreelyError
{
    public function __construct(
        string $message,
        string $code = 'billing_inactive',
        ?int $status = 402,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $status, null, $previous);
    }
}
