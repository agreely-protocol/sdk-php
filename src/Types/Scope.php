<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The /v1 API-key scope vocabulary, as {@see Identity::$scopes} reports it.
 *
 *   CHECK        the synchronous consent check, GET /v1/catalog, the published consent
 *                documents and their information PDF
 *   ISSUE        consent-request issuance and its reads, GET /v1/catalog, the published
 *                consent documents and their information PDF
 *   ATTEST       manual / offline (company-attested) consent recording, the signed
 *                paper of a verbal consent, revoke and erase of any company-recorded
 *                cell, claim links, consent sheets, and the information PDF
 *   ATTEST_VERBAL verbal (telephone, documented by the organisation) consent recording
 *                and its history, and the information PDF. Never granted by default. A
 *                key holding only this scope may revoke VERBAL cells only, and cannot
 *                erase or record a paper.
 *   RELATIONSHIP ending a customer relationship (art. 23)
 *   REGISTRY     one customer's registry identity, retention posture, dispositions and
 *                holds ({@see \Agreely\Sdk\Resources\Customers}). Addressed only by a
 *                customer_ref the host already knows: it has no list endpoint by
 *                construction.
 *   RETENTION    read the decided retention rules and catalogue cells, and declare
 *                the purges and passes a host system ran ({@see \Agreely\Sdk\Resources\Retention})
 *   INVENTORY    declare a host system's record sets and read their retention
 *                statements ({@see \Agreely\Sdk\Resources\Inventory})
 *   WITHDRAW     record a person's withdrawal of ANY consent ask on her behalf, a
 *                passkey-signed one included ({@see \Agreely\Sdk\Resources\Withdrawals}).
 *                Never granted by default, never implied by ATTEST, capped per day, and
 *                the one scope a company behind on its payments keeps.
 *   HOLDS        ONE read: the feed of the retention holds in place (references and
 *                scope, never the ground). Never granted by default; a purge job's key
 *                carries RETENTION and HOLDS.
 *
 * ⚠️ RETENTION AND INVENTORY ARE SEPARATE ON PURPOSE. Declaring an inventory
 * shapes the register the responsable reviews, so a purge cron holding 'retention'
 * must not be able to rewrite what the organisation says it holds. Reading ONE
 * statement by its key also accepts 'check', so a public collection form can
 * resolve the sentence it stamps on a record without holding a key that writes.
 *
 * FORWARD COMPATIBILITY: the server may add scopes, and identity() returns them as
 * it receives them, so a value outside this list can reach you at runtime.
 */
final class Scope
{
    public const CHECK        = 'check';
    public const ISSUE        = 'issue';
    public const ATTEST       = 'attest';
    public const ATTEST_VERBAL = 'attest_verbal';
    public const RELATIONSHIP = 'relationship';
    public const REGISTRY     = 'registry';
    public const RETENTION    = 'retention';
    public const INVENTORY    = 'inventory';
    public const WITHDRAW     = 'withdraw';
    public const HOLDS        = 'holds';

    /** Every scope the server declares today, in its own order. */
    public const ALL = [
        self::CHECK,
        self::ISSUE,
        self::ATTEST,
        self::ATTEST_VERBAL,
        self::RELATIONSHIP,
        self::REGISTRY,
        self::RETENTION,
        self::INVENTORY,
        self::WITHDRAW,
        self::HOLDS,
    ];

    private function __construct()
    {
    }
}
