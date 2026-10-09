<?php

declare(strict_types=1);

namespace Agreely\Sdk\Types;

/**
 * The least-disclosure identity of the presented key, from GET /v1/whoami: the key's
 * own scopes, and the minimum needed to discover its organisation
 * ({@see IdentityCompany}). No company id, no key name, no counters, no PII.
 * `baseUrl` is added CLIENT-SIDE (the configured endpoint), never part of the wire
 * body; it is null when the type is built from the wire alone. `company` is null only
 * when the server sent none.
 *
 * HOW TO USE IT AS A HEALTH PROBE: call identity() about once a minute from ONE
 * scheduler, never per request. On a 401 (AgreelyAuthError) or a 402
 * (AgreelyBillingInactiveError), purge anything cached from Agreely and fail closed.
 */
final class Identity
{
    /**
     * @param list<string> $scopes the presented key's scopes, as the server reports them
     */
    public function __construct(
        public readonly array $scopes,
        public readonly ?string $baseUrl = null,
        public readonly ?IdentityCompany $company = null,
    ) {
    }

    /**
     * @param array<string,mixed> $wire
     */
    public static function fromWire(array $wire, ?string $baseUrl = null): self
    {
        $company = $wire['company'] ?? null;
        /** @var array<string,mixed>|null $company */
        $company = is_array($company) ? $company : null;
        return new self(
            Wire::strings($wire, 'scopes'),
            $baseUrl,
            $company === null ? null : IdentityCompany::fromWire($company),
        );
    }

    /** True when the key carries $scope (a {@see Scope} constant). */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
