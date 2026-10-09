<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\HostInput;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\IdempotencyKey;
use Agreely\Sdk\Types\ConsentWithdrawal;

/**
 * Record a person's WITHDRAWAL of a consent, on her behalf (scope: 'withdraw').
 *
 * The organisation records the withdrawal she asked it for, by any channel, of ANY
 * consent ask it holds for her: a paper or telephone consent, and a consent she signed
 * online with her passkey. /v1/check denies at once. It is the only /v1 door to a
 * passkey-signed consent, which is why 'withdraw' is a scope of its own: never
 * pre-ticked on a key, never implied by 'attest', and capped per day.
 *
 * THE ONE ROUTE A COMPANY BEHIND ON ITS PAYMENTS KEEPS: honouring a withdrawal is a
 * legal duty, not a paid service, so it never answers the 402
 * (AgreelyBillingInactiveError) every other route does.
 */
final class Withdrawals
{
    /** How the person asked for the withdrawal: the server's closed vocabulary. */
    public const CHANNELS = ['phone', 'email', 'mail', 'in_person', 'other'];

    /** The `reason` bound, mirrored from the server. */
    public const REASON_MAX = 1000;

    /** The members a withdrawal accepts, and no others. */
    private const MEMBERS = ['channel', 'operator', 'requestedAt', 'reason'];

    /** An opaque staff id: letters, digits and . _ : - only, so neither an email address nor prose fits. */
    private const OPERATOR = '/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D';

    /** A consentRef: 32 bytes of hex, 0x-prefixed (the server also takes it bare). */
    private const CONSENT_REF = '/^(?:0[xX])?[0-9a-fA-F]{64}$/D';

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Record the withdrawal of $consentRef (0x-hex) for $customerRef, the company's own
     * reference for the person (POST /v1/customers/{customerRef}/consents/{consentRef}/withdrawal).
     *
     * - channel     REQUIRED, how the person asked: phone | email | mail | in_person | other
     * - operator    REQUIRED, YOUR opaque id of the staff member who received the request
     *               (letters, digits, . _ : -, at most 64). Never a name, never an email.
     * - requestedAt optional, when the person asked, AS DECLARED: a DateTimeInterface or
     *               RFC 3339 WITH an offset, not before the consent, not in the future. It
     *               never moves the withdrawal's own instant, which is the moment of the call.
     * - reason      optional free text, at most 1000 characters, no control character.
     *               Encrypted, destroyed on erasure, never on chain, never echoed back.
     *
     * Read the result's `gate` ({@see ConsentWithdrawal}), never `withdrawn` alone.
     * IDEMPOTENT: a repeat answers `alreadyWithdrawn: true`, `gate: "unchanged"`. An
     * Idempotency-Key is generated per call unless you pass one in $options (1 to 255
     * printable ASCII characters), bound by the server to this endpoint and the body.
     * Accepted after the relationship ended. NEVER auto-retried.
     *
     * Refusals ({@see \Agreely\Sdk\Errors\ErrorReason}): AgreelyNotFoundError reason
     * unknown_consent (a missing, malformed or foreign consentRef, an unknown
     * customerRef, and a consentRef of ANOTHER customer all answer the same); 409
     * AgreelyConflictError reason consent_lapsed (no longer in force: re-read
     * /v1/check, which names the consent in force, if any); 422 AgreelyValidationError
     * reason not_revocable (a cell that was never a consent ask), requested_at_*,
     * invalid_*; 429 AgreelyDailyCapError code withdrawal_daily_cap (the organisation's rolling 24-hour
     * cap, no Retry-After: record further withdrawals from the customer record in
     * Agreely).
     *
     * REFUSED CLIENT-SIDE, before any wire call (AgreelyConfigError): a missing or
     * unknown channel, an operator shaped like an email or prose, a requestedAt with
     * no offset, a reason over 1000 characters or with a control character, a blank
     * customerRef, a consentRef that is not 32 bytes of hex, and any member outside the
     * four above.
     *
     * @param array{channel:string,operator:string,requestedAt?:string|\DateTimeInterface,reason?:string} $input
     * @param array{idempotencyKey?:string} $options
     */
    public function record(string $customerRef, string $consentRef, array $input, array $options = []): ConsentWithdrawal
    {
        $label = 'withdrawals.record';
        $ref = HostInput::customerRef($customerRef, $label);
        $consent = trim($consentRef);
        if (preg_match(self::CONSENT_REF, $consent) !== 1) {
            throw new AgreelyConfigError("{$label}: consentRef must be the 0x-prefixed 64-hex consentRef.");
        }
        HostInput::closed($input, self::MEMBERS, $label);

        $channel = $input['channel'] ?? null;
        if (!is_string($channel) || !in_array($channel, self::CHANNELS, true)) {
            throw new AgreelyConfigError(
                "{$label}: channel must be one of " . implode(', ', self::CHANNELS) . '.',
            );
        }
        $operator = is_string($input['operator'] ?? null) ? trim($input['operator']) : '';
        if (preg_match(self::OPERATOR, $operator) !== 1) {
            throw new AgreelyConfigError(
                "{$label}: operator must be your opaque id of the staff member who received the request: letters, "
                . 'digits and . _ : - only, at most 64 characters. Never a name or an email address.',
            );
        }

        $body = ['channel' => $channel, 'operator' => $operator];
        if (array_key_exists('requestedAt', $input) && $input['requestedAt'] !== null) {
            $body['requestedAt'] = HostInput::instant($input['requestedAt'], "{$label}: requestedAt")['wire'];
        }
        if (array_key_exists('reason', $input) && $input['reason'] !== null) {
            $reason = $input['reason'];
            if (
                !is_string($reason)
                || ($reason !== '' && preg_match('/^[^\p{C}]{1,' . self::REASON_MAX . '}$/u', $reason) !== 1)
            ) {
                throw new AgreelyConfigError(
                    "{$label}: reason must be at most " . self::REASON_MAX . ' characters, with no control character.',
                );
            }
            if ($reason !== '') {
                $body['reason'] = $reason;
            }
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/customers/' . rawurlencode($ref) . '/consents/' . rawurlencode($consent) . '/withdrawal',
            body: $body,
            headers: ['Idempotency-Key' => IdempotencyKey::resolve($options, $label)],
            idempotentRetry: false,
        ));

        return ConsentWithdrawal::fromWire($wire);
    }
}
