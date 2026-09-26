<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 409 `retry`: a concurrent retry of the same declaration could not be settled.
 * Nothing new was recorded under this attempt.
 *
 * RETRY WITH THE SAME Idempotency-Key (and, after a key rotation, with the SAME
 * API key): that replays the declaration rather than recording a second one. A
 * fresh key here would record a duplicate purge in the register.
 *
 * It is NOT an outage (so the degrade policy never sees it) and NOT a rate limit
 * (so no Retry-After applies): it is a lost race on one declaration.
 */
class AgreelyConflictError extends AgreelyError
{
    public function __construct(
        string $message,
        string $code = 'retry',
        ?int $status = 409,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $status, null, $previous);
    }
}
