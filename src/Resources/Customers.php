<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\HostInput;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\Types\CustomerRecord;
use Agreely\Sdk\Types\UpsertCustomerResult;

/**
 * ONE CUSTOMER'S REGISTRY IDENTITY (scope: 'registry'): what Agreely holds for a
 * customer reference, as metadata. The same customer's retention posture, the
 * dispositions your systems declare and the retention holds are on
 * {@see Retention} (getCustomerRetention, declareDisposition, placeHold, releaseHold),
 * under the same scope.
 *
 * Every call is addressed by ONE customerRef you already hold (your own reference, the
 * one /v1/check takes, never a DID). There is no list, by construction: no shape here
 * returns more than one person. get() answers a reference this organisation never
 * touched with AgreelyNotFoundError; upsert() CREATES the record for it (201, `created`
 * true), and a reference another tenant uses is simply a different record in yours.
 */
final class Customers
{
    /** The identity fields upsert() accepts, and no others. */
    private const IDENTITY_MEMBERS = ['displayName', 'email', 'basisNote', 'legalBasis', 'noticeLocale'];

    /** The bounds the registry applies to a name and a basis note. */
    public const DISPLAY_NAME_MAX = 200;
    public const BASIS_NOTE_MAX = 2000;

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * RECORD OR AMEND the registry identity of one customer (PUT /v1/customers/{customerRef}).
     *
     * ⚠️ A MERGE, NEVER A REPLACE:
     *   - a field ABSENT from $fields is left exactly as it stands;
     *   - a field given as null or "" is CLEARED (« we do not hold this »);
     *   - a field given a value is written.
     * So a sync that only knows email addresses never wipes a name someone curated.
     *
     * Fields: displayName (at most 200 characters), email, basisNote (at most 2000),
     * legalBasis (a NON-consent ground of the organisation's own act: read the regime from
     * identity()->company; "consent" is refused, a client held on consent belongs to the
     * consent flow) and noticeLocale ("fr" or "en"). No consent side effect: no email, no
     * consent request, no enforcement record.
     *
     * The answer is metadata ({@see UpsertCustomerResult}): `created` says whether this
     * call created the row. After a declared destruction removed the identity, a value is a
     * 409 AgreelyConflictError code identity_erased (a clear still works); while a hold
     * on all the information keeps it, any change is a 409 identity_held.
     *
     * REFUSED CLIENT-SIDE (AgreelyConfigError): a member outside the five above, a value
     * that is neither a string nor null, legalBasis "consent", a noticeLocale other than
     * "fr" or "en", an over-long name or note. NEVER auto-retried.
     *
     * @param array{displayName?:string|null,email?:string|null,basisNote?:string|null,legalBasis?:string|null,noticeLocale?:string|null} $fields
     */
    public function upsert(string $customerRef, array $fields): UpsertCustomerResult
    {
        $label = 'customers.upsert';
        $ref = HostInput::customerRef($customerRef, $label);
        HostInput::closed($fields, self::IDENTITY_MEMBERS, $label);

        $body = [];
        foreach ($fields as $name => $value) {
            if ($value !== null && !is_string($value)) {
                throw new AgreelyConfigError("{$label}: {$name} must be a string, or null to clear it.");
            }
            $body[$name] = $value;
        }
        $text = static fn (string $name): string => trim((string) ($body[$name] ?? ''));
        if (mb_strlen($text('displayName')) > self::DISPLAY_NAME_MAX) {
            throw new AgreelyConfigError("{$label}: displayName must be at most " . self::DISPLAY_NAME_MAX . ' characters.');
        }
        if (mb_strlen($text('basisNote')) > self::BASIS_NOTE_MAX) {
            throw new AgreelyConfigError("{$label}: basisNote must be at most " . self::BASIS_NOTE_MAX . ' characters.');
        }
        if ($text('legalBasis') === 'consent') {
            throw new AgreelyConfigError(
                "{$label}: legalBasis cannot be \"consent\". The registry records clients held WITHOUT a consent, on a "
                . 'documented non-consent ground; a client held on consent belongs to consentRequests()->create().',
            );
        }
        if ($text('noticeLocale') !== '') {
            HostInput::locale($text('noticeLocale'), "{$label}: noticeLocale");
        }

        $answer = $this->transport->requestWithStatus(new RequestSpec(
            method: 'PUT',
            path: '/v1/customers/' . rawurlencode($ref),
            // An empty merge is sent as no body, never as a JSON list.
            body: $body === [] ? null : $body,
            idempotentRetry: false,
        ));
        return UpsertCustomerResult::fromWireCreated($answer['body'], $answer['status'] === 201);
    }

    /**
     * What Agreely holds for one customer reference, as METADATA (GET
     * /v1/customers/{customerRef}): whether a name, an email or a note is held, never
     * the value. A reference this organisation never touched is AgreelyNotFoundError.
     */
    public function get(string $customerRef): CustomerRecord
    {
        $ref = HostInput::customerRef($customerRef, 'customers.get');
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/customers/' . rawurlencode($ref),
            idempotentRetry: true,
        ));
        return CustomerRecord::fromWire($wire);
    }
}
