<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 503 / network error / timeout: Agreely was unreachable. This is the ONLY
 * error subject to the degrade policy. `retryable` marks the transient cases
 * (503, network, timeout) the transport may retry for idempotent calls.
 */
class AgreelyUnavailableError extends AgreelyError
{
    public readonly bool $retryable;

    /**
     * `code`, `field` and `reason` are the error envelope's when the server sent one (a
     * 5xx, or a status this client maps to no other error); a network failure or a
     * timeout reads code "unavailable".
     */
    public function __construct(
        string $message,
        ?int $status = null,
        bool $retryable = false,
        ?\Throwable $previous = null,
        string $code = 'unavailable',
        ?string $field = null,
        ?string $reason = null,
    ) {
        parent::__construct($message, $code, $status, $field, $previous, $reason);
        $this->retryable = $retryable;
    }
}
