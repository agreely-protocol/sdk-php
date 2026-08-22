<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The resolved status of an enforcement cell: the EIGHT-value vocabulary of
 * openapi.yaml CheckDecision.status / BatchDecision.status. The PHP twin of the TS
 * SDK's `Status` union.
 *
 * ALLOW:
 *   ACTIVE    a live consent record backs the cell (carries consentRef + assurance).
 *   NECESSITY there is NO per-subject record, but the active catalog cell declares a
 *             NON-consent lawful basis that is lawful to hold and use on its own
 *             (see {@see CheckBasis}). Carries `basis` and NO consentRef/assurance:
 *             there is no signed proof, and the check CREATES NOTHING. Never present
 *             a necessity allow as "consented".
 *
 * DENY:
 *   NONE      no record on a consent-basis cell (or no active catalog cell at all).
 *             Consent must refuse without a record. This is ALSO what an ERASED cell
 *             reads as, see below.
 *   REVOKED   the consent was withdrawn (art. 14).
 *   EXPIRED   the consent lifespan elapsed (art. 14 al. 3).
 *   ERASED    listed by openapi.yaml, but NOT currently emitted by the API. Erasure
 *             is a crypto-shred: it DELETES the enforcement record outright (art. 23),
 *             so an erased cell reads back as deny/"none", not deny/"erased". Handle
 *             it if you switch exhaustively, but do not expect it on the wire today.
 *   RELATIONSHIP_ENDED
 *             the company attested the relationship is over (art. 23): a prospective,
 *             relationship-level stop. The per-cell consent stays truthfully active
 *             (it was never withdrawn), which is why this is its own status.
 *   SENSITIVE_REQUIRES_CONSENT
 *             no record, and the company declared the catalog cell SENSITIVE, so it
 *             fails closed to express consent (art. 12 al. 1 in fine / art. 13).
 *             Nothing is created. A sensitive cell never greenlights on necessity alone.
 *
 * FORWARD COMPATIBILITY: the server may add statuses. Treat ANY status you do not
 * recognise as a DENY and read `decision`, which is only ever "allow" or "deny".
 */
final class CheckStatus
{
    public const ACTIVE                     = 'active';
    public const NECESSITY                  = 'necessity';
    public const NONE                       = 'none';
    public const REVOKED                    = 'revoked';
    public const EXPIRED                    = 'expired';
    public const ERASED                     = 'erased';
    public const RELATIONSHIP_ENDED         = 'relationship_ended';
    public const SENSITIVE_REQUIRES_CONSENT = 'sensitive_requires_consent';

    /** Every status openapi.yaml declares, in spec order. */
    public const ALL = [
        self::ACTIVE,
        self::NECESSITY,
        self::NONE,
        self::REVOKED,
        self::EXPIRED,
        self::ERASED,
        self::RELATIONSHIP_ENDED,
        self::SENSITIVE_REQUIRES_CONSENT,
    ];

    /** The two statuses that resolve to an allow. Everything else denies. */
    public const ALLOWING = [self::ACTIVE, self::NECESSITY];

    private function __construct()
    {
    }
}
