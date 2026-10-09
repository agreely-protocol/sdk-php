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

    /** The 429 codes of a rolling 24-hour cap: each raises an AgreelyDailyCapError. */
    public const DAILY_CAPS = [
        self::VERBAL_DAILY_CAP,
        self::WITHDRAWAL_DAILY_CAP,
        self::HOLD_BUDGET_EXHAUSTED,
        self::HOLD_RELEASE_CAP_REACHED,
    ];

    // 422 that name their refusal in the code.
    public const NO_CONSENT_ASK = 'no_consent_ask';
    public const ENGLISH_TEXT_MISSING = 'english_text_missing';
    public const TOO_MANY_HOLDS = 'too_many_holds';
    public const NEW_SET_LIMIT = 'new_set_limit';
    public const HOST_SYSTEM_LIMIT = 'host_system_limit';
    public const HOST_TOKEN_LIMIT = 'host_token_limit';
    public const IDEMPOTENCY_KEY_REQUIRED = 'idempotency_key_required';
    public const IDEMPOTENCY_KEY_REUSED = 'idempotency_key_reused';

    // 422 of the host declarations (purges, passes) and the inventory.
    public const INVALID_METHOD = 'invalid_method';
    public const ANONYMIZATION_PROCESS_REQUIRED = 'anonymization_process_required';
    public const INVALID_ANONYMIZATION_PROCESS = 'invalid_anonymization_process';
    public const INVALID_RECORDS_AFFECTED = 'invalid_records_affected';
    public const RAN_AT_IN_FUTURE = 'ran_at_in_future';
    public const SWEPT_AT_IN_FUTURE = 'swept_at_in_future';
    public const INVALID_COVERED_PERIOD = 'invalid_covered_period';
    public const INVALID_HOST_TOKEN = 'invalid_host_token';
    public const VALUE_SHAPED_LABEL = 'value_shaped_label';
    public const INVALID_KEY = 'invalid_key';
    public const DUPLICATE_KEY = 'duplicate_key';
    public const LIMIT_EXCEEDED = 'limit_exceeded';
    public const EMPTY_DECLARATION = 'empty_declaration';

    // Raised by a client, never sent by the server.
    public const CONFIG = 'config';
    public const TIMEOUT = 'timeout';
    /** The TypeScript twin's code for a call cancelled by its AbortSignal. This client has no such signal and never raises it. */
    public const ABORTED = 'aborted';

    private function __construct()
    {
    }
}
