<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * The `error.code` vocabulary: the HTTP-level class of an error, as
 * {@see AgreelyError::errorCode()} (or `$e->code`) reports it.
 *
 * Most refusals carry a generic code ("invalid_request", "conflict", "not_found")
 * and say WHICH refusal in `reason` ({@see ErrorReason}). A few surfaces name the
 * refusal in the code itself and send no reason: the retention holds, the registry
 * identity, the dispositions, the inventory and the host declarations. Branch on the
 * one the surface sends; both are stable, the message is not.
 *
 * FORWARD COMPATIBILITY: the server may add codes, and an error carries whatever it
 * received, so a value outside this list can reach you at runtime.
 */
final class ErrorCode
{
    // Every route.
    public const UNAUTHORIZED = 'unauthorized';
    public const FORBIDDEN = 'forbidden';
    public const INVALID_REQUEST = 'invalid_request';
    public const NOT_FOUND = 'not_found';
    public const RATE_LIMITED = 'rate_limited';
    public const UNAVAILABLE = 'unavailable';
    public const BILLING_INACTIVE = 'billing_inactive';
    public const BODY_TOO_LARGE = 'body_too_large';
    public const UNKNOWN_FIELD = 'unknown_field';

    // 409.
    public const CONFLICT = 'conflict';
    public const RETRY = 'retry';
    public const ALREADY_MINTED = 'already_minted';
    public const ALREADY_RELEASED = 'already_released';
    public const IDENTITY_HELD = 'identity_held';
    public const IDENTITY_ERASED = 'identity_erased';
    public const RELATIONSHIP_ACTIVE = 'relationship_active';

    // 429 caps other than the per-minute window: never auto-retried.
    public const VERBAL_DAILY_CAP = 'verbal_daily_cap';
    public const WITHDRAWAL_DAILY_CAP = 'withdrawal_daily_cap';
    public const HOLD_BUDGET_EXHAUSTED = 'hold_budget_exhausted';
    public const HOLD_RELEASE_CAP_REACHED = 'hold_release_cap_reached';
    public const SWEEP_TOO_FREQUENT = 'sweep_too_frequent';

    // 422 that name their refusal in the code.
    public const NO_CONSENT_ASK = 'no_consent_ask';
    public const ENGLISH_TEXT_MISSING = 'english_text_missing';
    public const TOO_MANY_HOLDS = 'too_many_holds';
    public const NEW_SET_LIMIT = 'new_set_limit';
    public const HOST_SYSTEM_LIMIT = 'host_system_limit';
    public const HOST_TOKEN_LIMIT = 'host_token_limit';
    public const IDEMPOTENCY_KEY_REQUIRED = 'idempotency_key_required';
    public const IDEMPOTENCY_KEY_REUSED = 'idempotency_key_reused';

    // Raised by this client, never sent by the server.
    public const CONFIG = 'config';
    public const TIMEOUT = 'timeout';

    private function __construct()
    {
    }
}
