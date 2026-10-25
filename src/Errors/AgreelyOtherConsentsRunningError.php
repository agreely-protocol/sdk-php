<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * 422 `other_consents_running` on a withdrawal: another consent of the same purpose is in force, given at or before the
 * request, and the call declared neither `otherConsents` nor `alsoWithdraw`. Nothing was written.
 *
 * `otherConsentRefs` lists the 0x consentRefs of those consents, all of them, so the caller can ASK THE PERSON which ones
 * her withdrawal covers, then retry with `otherConsents` ("withdraw" or "keep") or with `alsoWithdraw` listing the refs
 * she chose. A subclass of AgreelyValidationError, so a generic validation catch still catches it.
 */
class AgreelyOtherConsentsRunningError extends AgreelyValidationError
{
    /** @param list<string> $otherConsentRefs */
    public function __construct(
        string $message,
        string $code = 'invalid_request',
        ?int $status = 422,
        ?string $field = 'otherConsents',
        ?\Throwable $previous = null,
        ?string $reason = ErrorReason::OTHER_CONSENTS_RUNNING,
        public readonly array $otherConsentRefs = [],
    ) {
        parent::__construct($message, $code, $status, $field, $previous, $reason);
    }
}
