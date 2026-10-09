<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 409. Several situations share this status, and `code` tells them apart:
 *
 *   `retry`    a concurrent retry of the same declaration could not be settled.
 *              Nothing new was recorded under this attempt. RETRY WITH THE SAME
 *              Idempotency-Key (and, after a key rotation, with the SAME API key):
 *              that replays the declaration rather than recording a second one.
 *
 *   `conflict` the request contradicts the record's current state, and RETRYING IT
 *              CHANGES NOTHING. `reason` says which state ({@see ErrorReason}):
 *              stronger_consent_active, relationship_ended, superseded_by_paper,
 *              citizen_consent_at_gate, consent_lapsed, renewal_ends_before_current,
 *              predates_withdrawal, already_confirmed... Read it, fix the state or
 *              the request, and do not loop.
 *
 *   Other codes name their own state and are never retryable either:
 *   `identity_held` / `identity_erased` (a registry write a standing destruction or
 *   a keeping hold closes), `already_released` (a hold lifted already),
 *   `already_minted` (a consent-sheet Idempotency-Key already used: its reference
 *   was returned once and is not kept), `relationship_active` (a disposition
 *   against a relationship that has not ended). {@see ErrorCode} names them.
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
        ?string $reason = null,
        ?string $field = null,
    ) {
        parent::__construct($message, $code, $status, $field, $previous, $reason);
    }

    /** True for `retry`: re-send with the SAME Idempotency-Key. */
    public function isRetryable(): bool
    {
        return $this->errorCode() === ErrorCode::RETRY;
    }

    /** True for `conflict`: a state conflict that no retry resolves; read `reason`. */
    public function isStateConflict(): bool
    {
        return $this->errorCode() === ErrorCode::CONFLICT;
    }
}
