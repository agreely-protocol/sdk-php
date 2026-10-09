<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 429 `sweep_too_frequent`: a pass was already declared under this rule FROM THIS
 * hostSystem less than 15 minutes ago.
 *
 * It is the per-(rule, hostSystem) floor, NOT the per-company rate window, and two
 * systems sharing a rule never block each other. A subclass of
 * AgreelyRateLimitError, so a generic rate-limit catch still catches it, and
 * `retryAfter` carries the Retry-After header.
 *
 * ⚠️ DO NOT LOOP ON IT. The pass you are declaring is already represented by the
 * one declared less than 15 minutes ago: drop it. Wait `retryAfter` seconds only
 * if this pass genuinely ran later. The SDK NEVER auto-retries it, even when
 * maxRetries is set.
 */
class AgreelySweepTooFrequentError extends AgreelyRateLimitError
{
    public function __construct(
        string $message,
        string $code = 'sweep_too_frequent',
        ?int $status = 429,
        ?int $retryAfter = null,
        ?\Throwable $previous = null,
        ?string $reason = null,
    ) {
        parent::__construct($message, $code, $status, $retryAfter, $previous, $reason);
    }
}
