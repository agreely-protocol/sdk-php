<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * Base class for every error the SDK throws. A consent DENY is NOT an error (it
 * is a 200) and never appears here. An integrator can
 * `catch (AgreelyRateLimitError $e)` to branch on the specific failure.
 *
 * Mirrors the TypeScript SDK's AgreelyError (the contract reference).
 *
 * THREE MACHINE-READABLE FIELDS, and the message is none of them:
 *   code    the HTTP-level class of the error ({@see ErrorCode})
 *   reason  the STABLE machine reason of a refusal, when the server sent one
 *           ({@see ErrorReason}). Branch on it, never on the English message,
 *           which may be reworded at any time
 *   field   the offending input field, when the refusal is about one
 *
 * @property-read string $code The canonical wire error code.
 */
class AgreelyError extends \RuntimeException
{
    /**
     * The canonical wire error code (`error.code`), e.g. "forbidden", "conflict",
     * "already_released". {@see ErrorCode} names the known ones; a code the server
     * adds later reaches you as the plain string.
     *
     * Exposed as `$e->code` via __get (the name clashes with Exception::$code,
     * which is a non-readonly int, so it cannot be redeclared directly).
     */
    private readonly string $errorCode;

    /** HTTP status, when the error came from a response. */
    public readonly ?int $status;

    /** The offending input field (`error.field`), when the server named one. */
    public readonly ?string $field;

    /**
     * The STABLE machine reason of the refusal (`error.reason`), or null when the
     * server sent none (a 401, 402, 403 or a per-minute 429 is told by `code`
     * alone). {@see ErrorReason} names the known values. FORWARD COMPATIBLE: a
     * reason the server adds later is readable here as the plain string, it never
     * throws and never maps to anything else.
     */
    public readonly ?string $reason;

    public function __construct(
        string $message,
        string $code,
        ?int $status = null,
        ?string $field = null,
        ?\Throwable $previous = null,
        ?string $reason = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $code;
        $this->status = $status;
        $this->field = $field;
        $this->reason = $reason;
    }

    /** The canonical wire error code (a string, e.g. "forbidden"). */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** True when the server's stable `reason` is exactly $reason (an {@see ErrorReason} constant). */
    public function hasReason(string $reason): bool
    {
        return $this->reason === $reason;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'code') {
            return $this->errorCode;
        }
        throw new \LogicException(
            'Undefined property: ' . static::class . '::$' . $name,
        );
    }

    public function __isset(string $name): bool
    {
        return $name === 'code';
    }
}
