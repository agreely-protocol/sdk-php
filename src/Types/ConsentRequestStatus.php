<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The EFFECTIVE status of a consent request (openapi ConsentRequest.status), and the
 * values GET /v1/consent-requests accepts as its `status` filter.
 *
 *   PENDING               issued, not answered yet (reads EXPIRED once its answer
 *                         deadline passed)
 *   APPROVED              the person confirmed and, when the request carried consent
 *                         asks, accepted AT LEAST ONE of them
 *   ASKS_DECLINED         the person confirmed only her receipt of the lines given for
 *                         information and declined EVERY consent ask: no consent was
 *                         obtained. Never returned as APPROVED, nor under
 *                         ?status=approved. Terminal.
 *   REFUSED               the person refused
 *   EXPIRED               nobody answered in time
 *   REVOKED_BEFORE_ACTION the company cancelled it while pending
 *
 * Before 0.4.0 an all-declined answer read "approved". Code that treated "approved"
 * as "a consent now exists" was wrong for that case, and must now also handle
 * ASKS_DECLINED as a settled outcome.
 */
final class ConsentRequestStatus
{
    public const PENDING               = 'pending';
    public const APPROVED              = 'approved';
    public const ASKS_DECLINED         = 'asks_declined';
    public const REFUSED               = 'refused';
    public const EXPIRED               = 'expired';
    public const REVOKED_BEFORE_ACTION = 'revoked_before_action';

    /** Every status openapi.yaml declares, in spec order. */
    public const ALL = [
        self::PENDING,
        self::APPROVED,
        self::ASKS_DECLINED,
        self::REFUSED,
        self::EXPIRED,
        self::REVOKED_BEFORE_ACTION,
    ];

    /** The settled statuses: everything but PENDING. */
    public const TERMINAL = [
        self::APPROVED,
        self::ASKS_DECLINED,
        self::REFUSED,
        self::EXPIRED,
        self::REVOKED_BEFORE_ACTION,
    ];

    private function __construct()
    {
    }
}
