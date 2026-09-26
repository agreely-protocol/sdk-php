<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The /v1 API-key scope vocabulary, as {@see Identity::$scopes} reports it.
 *
 *   CHECK        the synchronous consent check, and GET /v1/catalog
 *   ISSUE        consent-request issuance and its reads, and GET /v1/catalog
 *   ATTEST       manual / offline (company-attested) consent recording
 *   RELATIONSHIP ending a customer relationship (art. 23)
 *   REGISTRY     the customer registry. NO SDK RESOURCE WRAPS IT: it is in the
 *                vocabulary because a key can carry it and identity() will report
 *                it, not because this client can call it. It is addressed only by
 *                a customer_ref the host already knows, and has no list endpoint
 *                by construction.
 *   RETENTION    read the decided retention rules and catalogue cells, and declare
 *                the purges and passes a host system ran ({@see \Agreely\Sdk\Resources\Retention})
 *   INVENTORY    declare a host system's record sets and read their retention
 *                statements ({@see \Agreely\Sdk\Resources\Inventory})
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
    public const RELATIONSHIP = 'relationship';
    public const REGISTRY     = 'registry';
    public const RETENTION    = 'retention';
    public const INVENTORY    = 'inventory';

    /** Every scope the server declares today, in its own order. */
    public const ALL = [
        self::CHECK,
        self::ISSUE,
        self::ATTEST,
        self::RELATIONSHIP,
        self::REGISTRY,
        self::RETENTION,
        self::INVENTORY,
    ];

    private function __construct()
    {
    }
}
