<?php

declare(strict_types=1);

namespace Agreely\Sdk\Degrade;

/**
 * The evidence record emitted ONCE per purge run that proceeds on STORED rules
 * (passed to the mandatory `onDegrade` of rulesForPurge).
 *
 * A run that abstains emits nothing: there is nothing to account for, because
 * nothing was destroyed.
 */
final class RetentionDegradeContext
{
    public function __construct(
        /** When the stored rules were last read from Agreely (the snapshot's fetchedAt). */
        public readonly string $snapshotFetchedAt,
        /** How old the stored rules were when this run used them. */
        public readonly float $staleForMs,
        /** The underlying outage error (503 / network / timeout). */
        public readonly \Throwable $error,
        /** RFC 3339 UTC timestamp of the decision. */
        public readonly string $at,
    ) {
    }
}
