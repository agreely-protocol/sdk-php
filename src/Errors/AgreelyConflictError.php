<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 409. Two different situations share this status, and `code` tells them apart:
 *
 *   `retry`    a concurrent retry of the same declaration could not be settled.
 *              Nothing new was recorded under this attempt. RETRY WITH THE SAME
 *              Idempotency-Key (and, after a key rotation, with the SAME API key):
 *              that replays the declaration rather than recording a second one.
 *
 *   `conflict` the request contradicts the record's current state, and RETRYING IT
 *              CHANGES NOTHING. Examples: a purpose already held by an active
 *              consent of an equal or stronger tier (a paper or telephone consent
 *              never replaces it), a relationship that has ended, a verbal
 *              consent's paper already recorded, a manual consent for a customer and
 *              document while a verbal consent awaits its paper, a verbal consentRef
 *              whose paper came back (withdraw or erase the confirming manual one).
 *              Read the message, fix the state or the request, and do not loop.
 *
 * It is NOT an outage (so the degrade policy never sees it) and NOT a rate limit
 * (so no Retry-After applies).
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

    /** True for `retry`: re-send with the SAME Idempotency-Key. */
    public function isRetryable(): bool
    {
        return $this->errorCode() === 'retry';
    }

    /** True for `conflict`: a state conflict that no retry resolves. */
    public function isStateConflict(): bool
    {
        return $this->errorCode() === 'conflict';
    }
}
