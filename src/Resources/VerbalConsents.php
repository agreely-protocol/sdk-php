<?php

declare(strict_types=1);

namespace Agreely\Sdk\Resources;

use Agreely\Sdk\Errors\AgreelyConfigError;
use Agreely\Sdk\HostInput;
use Agreely\Sdk\Http\RequestSpec;
use Agreely\Sdk\Http\Transport;
use Agreely\Sdk\IdempotencyKey;
use Agreely\Sdk\Types\VerbalConsentHistory;
use Agreely\Sdk\Types\VerbalConsentResult;
use Agreely\Sdk\Types\VerbalPaperResult;

/**
 * The VERBAL consent resource: a consent the person gave BY TELEPHONE, documented by
 * the organisation. The weakest of three tiers (full > manual > verbal): there is no
 * document at all, so the proof is the organisation's word. Every result names it
 * tier "verbal" / assurance "company_documented", and so does /v1/check, so the HOST
 * decides what a telephone consent may unlock.
 *
 * Scopes, deliberately split:
 *   record()           'attest_verbal' (a separate grant, never ticked by default)
 *   confirmWithPaper() 'attest' (the paper mints a company-attested consent, so
 *                      'attest_verbal' alone answers 403)
 *   get()              'attest_verbal' or 'attest'
 * Withdraw a verbal cell with manualConsents()->revoke() ('attest' or
 * 'attest_verbal'); erase it with manualConsents()->erase() ('attest' only).
 *
 * Writes are NEVER auto-retried. Each carries an Idempotency-Key (generated, or yours
 * via $options['idempotencyKey']: 1 to 255 printable ASCII characters), which the
 * server binds to the endpoint and to the request body.
 */
final class VerbalConsents
{
    private const RECORD_MEMBERS = [
        'customerId', 'documentVersionId', 'answers', 'obtainedAt', 'obtainedBy', 'scriptVersion',
        'respondent', 'validUntil', 'sensitiveExpressAttested', 'isMinor', 'paperExpected',
    ];

    private const PAPER_MEMBERS = ['signedAt', 'answers', 'evidence'];

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Record a telephone consent. Every consent ASK put on the call is answered
     * explicitly, "yes" or "no" (a boolean is refused: a purpose is never consented by
     * default); only the "yes" purposes become consents and each "no" stays in the
     * signed receipt. Never answer a line the document gives for information: the
     * server refuses it (422) and records every such line itself, as acknowledged from
     * the script.
     *
     * - obtainedAt: the instant of the call (the consent date), a DateTimeInterface or
     *   RFC 3339 WITH an offset; not in the future, at most 7 days old.
     * - validUntil: a plain date means through the END of that calendar day in the
     *   tenant's timezone; an instant needs an offset; relative phrases are refused;
     *   at most ten years after the call (an Agreely product rule, not the law's).
     * - respondent: consentedBy self | self_with_assistant | representative, plus
     *   representativeCapacity for a representative. For a minor under 14 (isMinor
     *   true) the respondent must be a representative in the capacity
     *   titulaire_autorite_parentale or tuteur_mineur, named in respondent.name.
     * - sensitiveExpressAttested must be true when any "yes" purpose is sensitive AND
     *   rests on the consent basis. isMinor, paperExpected and sensitiveExpressAttested
     *   are sent only when true.
     *
     * - scriptVersion: YOUR OWN label for the script the agent read (Agreely keeps it
     *   as given; it names no Agreely object).
     *
     * RENEWAL OVER PAPER. A signed paper consent whose end falls within its last 30
     * calendar days (an Agreely product rule, not a statutory period) can be renewed by
     * telephone with this same call: a "yes" for that purpose is a NEW consent, the paper
     * is never rewritten, and /v1/check answers tier "verbal" with the new validUntil
     * until the new signed paper comes back (confirmWithPaper(), which raises it to
     * "manual" with that same validUntil). The renewal may not end before the paper
     * (409 reason renewal_ends_before_current) nor be dated before its signature (409
     * renewal_predates_current). A "no" at renewal withdraws nothing. Withdrawing either
     * consent later withdraws both. A passkey consent is never renewed by telephone.
     *
     * Refusals, each with a stable `reason` ({@see \Agreely\Sdk\Errors\ErrorReason}):
     * 422 AgreelyValidationError (bad input, a notice-only document, an answer on an
     * informed line, an obtainedAt in the future by any amount: obtained_at_in_future);
     * 409 AgreelyConflictError code "conflict", do not retry (stronger_consent_active or
     * all_covered: a purpose held by an active paper or passkey consent outside the
     * renewal window; relationship_ended; predates_withdrawal: a call dated before a
     * withdrawal recorded for the same purpose; the two renewal reasons above); 429
     * AgreelyVerbalDailyCapError (the organisation's daily limit of verbal consents,
     * never auto-retried).
     *
     * @param array{
     *     customerId:string,
     *     documentVersionId:string,
     *     answers:list<array{category:string,purpose:string,answer:string}>,
     *     obtainedAt:string|\DateTimeInterface,
     *     obtainedBy:string,
     *     scriptVersion:string,
     *     respondent:array{consentedBy:string,representativeCapacity?:string,name?:string},
     *     validUntil:string,
     *     sensitiveExpressAttested?:bool,
     *     isMinor?:bool,
     *     paperExpected?:bool
     * } $input
     * @param array{idempotencyKey?:string} $options
     */
    public function record(array $input, array $options = []): VerbalConsentResult
    {
        $label = 'verbalConsents.record';
        HostInput::closed($input, self::RECORD_MEMBERS, $label);
        HostInput::closed($options, ['idempotencyKey'], $label);
        foreach (['customerId', 'documentVersionId', 'obtainedBy', 'scriptVersion', 'validUntil'] as $required) {
            if (!isset($input[$required]) || !is_string($input[$required]) || trim($input[$required]) === '') {
                throw new AgreelyConfigError("{$label} requires \"{$required}\".");
            }
        }
        $respondent = $input['respondent'] ?? null;
        if (!is_array($respondent) || !isset($respondent['consentedBy']) || !is_string($respondent['consentedBy'])) {
            throw new AgreelyConfigError("{$label} requires \"respondent.consentedBy\".");
        }
        HostInput::closed($respondent, ['consentedBy', 'representativeCapacity', 'name'], "{$label} respondent");

        $body = [
            'customerId' => $input['customerId'],
            'documentVersionId' => $input['documentVersionId'],
            'answers' => self::answers($input['answers'] ?? null, $label),
            'obtainedAt' => HostInput::instant($input['obtainedAt'] ?? null, "{$label} obtainedAt")['wire'],
            'obtainedBy' => $input['obtainedBy'],
            'scriptVersion' => $input['scriptVersion'],
            'respondent' => $respondent,
            'validUntil' => $input['validUntil'],
        ];
        // JSON true and nothing else: the server reads a "true" string or a 1 as absent.
        foreach (['sensitiveExpressAttested', 'isMinor', 'paperExpected'] as $flag) {
            if (($input[$flag] ?? false) === true) {
                $body[$flag] = true;
            }
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/verbal-consents',
            body: $body,
            headers: ['Idempotency-Key' => IdempotencyKey::resolve($options, $label)],
            idempotentRetry: false,
        ));

        return VerbalConsentResult::fromWire($wire);
    }

    /**
     * The signed paper came back (the rise to manual). Scope 'attest'. `answers` holds
     * EXACTLY the purposes still consented by telephone, each once, "yes" when ticked
     * on the paper and "no" when unticked. Each "yes" moves into a NEW manual consent
     * (tier "manual") dated from the CALL with the signature as its evidence date; each
     * "no" is recorded as a withdrawal dated at the signature. The verbal consent is
     * never rewritten. A purpose first consented on paper is a new consent
     * (manualConsents()->record).
     *
     * - signedAt: a DateTimeInterface or RFC 3339 WITH an offset, no earlier than the
     *   call and not in the future by any amount (422 reason signed_at_in_future).
     * - evidence.pdfSha256 is required; evidence.pdf (base64) is optional escrow and
     *   must hash to it (422 otherwise); an empty or non-PDF file is refused.
     *
     * One paper per verbal consent: a second one (reason already_confirmed), or a
     * relationship that has ended (relationship_ended), is AgreelyConflictError (409). An unknown, foreign or non-verbal id is
     * AgreelyNotFoundError (404).
     *
     * @param array{
     *     signedAt:string|\DateTimeInterface,
     *     answers:list<array{category:string,purpose:string,answer:string}>,
     *     evidence:array{pdfSha256:string,pdf?:string}
     * } $input
     * @param array{idempotencyKey?:string} $options
     */
    public function confirmWithPaper(string $consentId, array $input, array $options = []): VerbalPaperResult
    {
        $label = 'verbalConsents.confirmWithPaper';
        $id = HostInput::pathKey($consentId, $label, 'consentId');
        HostInput::closed($input, self::PAPER_MEMBERS, $label);
        HostInput::closed($options, ['idempotencyKey'], $label);
        $evidence = $input['evidence'] ?? null;
        if (!is_array($evidence) || !isset($evidence['pdfSha256']) || !is_string($evidence['pdfSha256'])) {
            throw new AgreelyConfigError("{$label} requires \"evidence.pdfSha256\".");
        }
        $wireEvidence = ['pdfSha256' => $evidence['pdfSha256']];
        if (isset($evidence['pdf'])) {
            $wireEvidence['pdf'] = $evidence['pdf'];
        }

        $wire = $this->transport->request(new RequestSpec(
            method: 'POST',
            path: '/v1/verbal-consents/' . rawurlencode($id) . '/paper',
            body: [
                'signedAt' => HostInput::instant($input['signedAt'] ?? null, "{$label} signedAt")['wire'],
                'answers' => self::answers($input['answers'] ?? null, $label),
                'evidence' => $wireEvidence,
            ],
            headers: ['Idempotency-Key' => IdempotencyKey::resolve($options, $label)],
            idempotentRetry: false,
        ));

        return VerbalPaperResult::fromWire($wire);
    }

    /**
     * The history of a verbal consent: what was said on the telephone, what the paper
     * said, and what is in force now. Scope 'attest_verbal' or 'attest'. A missing,
     * foreign, malformed or non-verbal id is AgreelyNotFoundError (404).
     */
    public function get(string $consentId): VerbalConsentHistory
    {
        $id = HostInput::pathKey($consentId, 'verbalConsents.get', 'consentId');
        $wire = $this->transport->request(new RequestSpec(
            method: 'GET',
            path: '/v1/verbal-consents/' . rawurlencode($id),
            idempotentRetry: true,
        ));

        return VerbalConsentHistory::fromWire($wire);
    }

    /**
     * The answers, checked before the call: a non-empty list, each a {category,
     * purpose, answer} with answer exactly "yes" or "no".
     *
     * @return list<array{category:string,purpose:string,answer:string}>
     */
    private static function answers(mixed $answers, string $label): array
    {
        if (!is_array($answers) || $answers === [] || !array_is_list($answers)) {
            throw new AgreelyConfigError("{$label} requires a non-empty \"answers\" list.");
        }
        $out = [];
        foreach ($answers as $i => $answer) {
            if (!is_array($answer)) {
                throw new AgreelyConfigError("{$label}: answers[{$i}] must be {category, purpose, answer}.");
            }
            HostInput::closed($answer, ['category', 'purpose', 'answer'], "{$label} answers[{$i}]");
            $category = $answer['category'] ?? null;
            $purpose = $answer['purpose'] ?? null;
            $value = $answer['answer'] ?? null;
            if (!is_string($category) || !is_string($purpose)) {
                throw new AgreelyConfigError("{$label}: answers[{$i}] requires a category and a purpose.");
            }
            if ($value !== 'yes' && $value !== 'no') {
                throw new AgreelyConfigError(
                    "{$label}: answers[{$i}].answer must be exactly \"yes\" or \"no\". A purpose is never consented "
                    . 'by default, and a line given for information is not answered.',
                );
            }
            $out[] = ['category' => $category, 'purpose' => $purpose, 'answer' => $value];
        }
        return $out;
    }
}
