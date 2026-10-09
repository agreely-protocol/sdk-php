<?php

declare(strict_types=1);

namespace Agreely\Sdk\Errors;

/**
 * The STABLE `error.reason` vocabulary, as {@see AgreelyError::$reason} reports it.
 *
 * Every refusal of the manual, verbal, claim-link, consent-sheet and withdrawal routes
 * carries one, beside a generic `code`. Compare `$e->reason` (or call
 * `$e->hasReason(ErrorReason::X)`), NEVER the English message, which may be reworded.
 *
 * FORWARD COMPATIBILITY: the server may add reasons. An unknown one is readable as
 * the plain string on `$e->reason`, never throws and never maps to another value:
 * fall back on `$e->code` for it.
 */
final class ErrorReason
{
    // Refused before any rule, on every route that sends reasons.
    public const MISSING_FIELD = 'missing_field';
    public const INVALID_FIELD = 'invalid_field';
    public const INVALID_JSON = 'invalid_json';
    public const INVALID_IDEMPOTENCY_KEY = 'invalid_idempotency_key';
    public const UNKNOWN_FIELD = 'unknown_field';
    public const BODY_TOO_LARGE = 'body_too_large';

    // A reference that does not resolve: one reason for missing, malformed and foreign.
    public const NOT_FOUND = 'not_found';
    public const UNKNOWN_CONSENT = 'unknown_consent';
    public const UNKNOWN_CUSTOMER = 'unknown_customer';

    // State conflicts (409, code "conflict").
    public const STRONGER_CONSENT_ACTIVE = 'stronger_consent_active';
    public const ALL_COVERED = 'all_covered';
    public const RELATIONSHIP_ENDED = 'relationship_ended';
    public const SUPERSEDED_BY_PAPER = 'superseded_by_paper';
    public const CITIZEN_CONSENT_AT_GATE = 'citizen_consent_at_gate';
    public const CONSENT_LAPSED = 'consent_lapsed';
    public const VERBAL_AWAITS_PAPER = 'verbal_awaits_paper';
    public const ALREADY_CONFIRMED = 'already_confirmed';
    public const ALREADY_MINTED = 'already_minted';
    public const PREDATES_WITHDRAWAL = 'predates_withdrawal';
    public const RENEWAL_ENDS_BEFORE_CURRENT = 'renewal_ends_before_current';
    public const RENEWAL_PREDATES_CURRENT = 'renewal_predates_current';
    public const LAPSED = 'lapsed';

    // Instants and dates.
    public const OBTAINED_AT_IN_FUTURE = 'obtained_at_in_future';
    public const SIGNED_AT_IN_FUTURE = 'signed_at_in_future';
    public const EFFECTIVE_DATE_IN_FUTURE = 'effective_date_in_future';
    public const INVALID_OBTAINED_AT = 'invalid_obtained_at';
    public const INVALID_SIGNED_AT = 'invalid_signed_at';
    public const INVALID_EFFECTIVE_DATE = 'invalid_effective_date';
    public const INVALID_VALID_UNTIL = 'invalid_valid_until';
    public const VALID_UNTIL_TOO_FAR = 'valid_until_too_far';
    public const VALID_UNTIL_IN_PAST = 'valid_until_in_past';

    // The document, the items and the evidence.
    public const NO_VERIFIED_DOMAIN = 'no_verified_domain';
    public const INVALID_CUSTOMER_ID = 'invalid_customer_id';
    public const INVALID_DOCUMENT = 'invalid_document';
    public const DOCUMENT_PLACEHOLDER = 'document_placeholder';
    public const NOTICE_ONLY = 'notice_only';
    public const NO_ITEMS = 'no_items';
    public const INVALID_ITEMS = 'invalid_items';
    public const INVALID_PDF_HASH = 'invalid_pdf_hash';
    public const PDF_HASH_MISMATCH = 'pdf_hash_mismatch';
    public const PDF_NOT_PDF = 'pdf_not_pdf';
    public const PDF_EMPTY = 'pdf_empty';
    public const VERSION_ATTESTATION_REQUIRED = 'version_attestation_required';
    public const SENSITIVE_EXPRESS_REQUIRED = 'sensitive_express_required';
    public const CAPACITY_DECLARATION_REQUIRED = 'capacity_declaration_required';
    public const CAPACITY_VERIFICATION_INVALID = 'capacity_verification_invalid';
    public const CLAIM_LINK_ALREADY_LIVE = 'claim_link_already_live';

    // The telephone call and its paper.
    public const INVALID_ANSWERS = 'invalid_answers';
    public const DUPLICATE_ANSWER = 'duplicate_answer';
    public const INFORMED_LINE_ANSWERED = 'informed_line_answered';
    public const NOTHING_CONSENTED = 'nothing_consented';
    public const INVALID_OBTAINED_BY = 'invalid_obtained_by';
    public const INVALID_SCRIPT_VERSION = 'invalid_script_version';
    public const INVALID_RESPONDENT = 'invalid_respondent';
    public const MINOR_NEEDS_GUARDIAN = 'minor_needs_guardian';
    public const NOTHING_TO_CONFIRM = 'nothing_to_confirm';
    public const PAPER_PURPOSE_NOT_CONSENTED_BY_PHONE = 'paper_purpose_not_consented_by_phone';
    public const PAPER_ANSWER_MISSING = 'paper_answer_missing';

    // A withdrawal recorded on the person's behalf.
    public const NOT_REVOCABLE = 'not_revocable';
    public const INVALID_CHANNEL = 'invalid_channel';
    public const INVALID_OPERATOR = 'invalid_operator';
    public const REQUESTED_AT_INVALID = 'requested_at_invalid';
    public const REQUESTED_AT_IN_FUTURE = 'requested_at_in_future';
    public const REQUESTED_AT_BEFORE_GRANT = 'requested_at_before_grant';
    public const INVALID_REASON = 'invalid_reason';

    // A daily cap (429): on the verbal record, and on the withdrawal.
    public const DAILY_CAP = 'daily_cap';

    private function __construct()
    {
    }
}
