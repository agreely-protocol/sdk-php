<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The resolved status of an enforcement cell: the NINE-value vocabulary of
 * openapi.yaml CheckDecision.status / BatchDecision.status. The PHP twin of the TS
 * SDK's `Status` union.
 *
 * ALLOW:
 *   ACTIVE    a live consent record backs the cell (carries consentRef, assurance
 *             and tier).
 *   NECESSITY there is NO per-subject record, but the active catalog cell declares a
 *             NON-consent lawful basis that is lawful to hold and use on its own
 *             (see {@see CheckBasis}). Carries `basis` and NO consentRef/assurance:
 *             there is no signed proof, and the check CREATES NOTHING. Never present
 *             a necessity allow as "consented".
 *             A cell declared SENSITIVE answers by its basis like any other: on a
 *             non-consent ground the tenant's act carries, a sensitive cell with no
 *             record allows "necessity" too.
 *
 * DENY:
 *   NONE      no record on a consent-basis cell (or no active catalog cell at all).
 *             Consent must refuse without a record; on a SENSITIVE consent-basis
 *             cell that consent must also be express. This is ALSO what an ERASED
 *             cell reads as, see below.
 *
 *   An ACKNOWLEDGED INFORMED LINE (a line a document gave for information, recorded
 *   as an acknowledgement and never as a consent) answers exactly like no record:
 *   NECESSITY + basis, or NONE, with no consentRef and no assurance/tier. Once it
 *   is withdrawn it denies REVOKED with a consentRef and still no assurance/tier.
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
 *   REQUIRES_DEPERSONALIZATION
 *             the cell declares a ground the tenant's act carries ONLY for
 *             depersonalized information (A-2.1 art. 65.1 al. 2 (4 deg)), which cannot
 *             be met for a named customer reference. It is NOT "no consent on record"
 *             and must not be answered by collecting a consent.
 *   BASIS_NOT_IN_REGIME
 *             the cell declares a ground the tenant's act does not carry at all
 *             (typically left in the other sector's vocabulary). The fix is a catalog
 *             re-declaration by the tenant.
 *
 * "sensitive_requires_consent" is NO LONGER EMITTED (since 2026-09-28) and the
 * constant was removed in 0.4.0: a sensitive cell now answers by its declared basis.
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
    public const REQUIRES_DEPERSONALIZATION = 'requires_depersonalization';
    public const BASIS_NOT_IN_REGIME        = 'basis_not_in_regime';

    /** Every status openapi.yaml declares, in spec order. */
    public const ALL = [
        self::ACTIVE,
        self::NECESSITY,
        self::NONE,
        self::REVOKED,
        self::EXPIRED,
        self::ERASED,
        self::RELATIONSHIP_ENDED,
        self::REQUIRES_DEPERSONALIZATION,
        self::BASIS_NOT_IN_REGIME,
    ];

    /** The two statuses that resolve to an allow. Everything else denies. */
    public const ALLOWING = [self::ACTIVE, self::NECESSITY];

    private function __construct()
    {
    }
}
