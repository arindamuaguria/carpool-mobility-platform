<?php

declare(strict_types=1);

namespace Tests\Domain\Authorisation\Doubles;

use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Idempotency\ActorReference;

/**
 * A record the platform holds, standing in for an aggregate.
 *
 * The parties come from the record itself, which is the point: `BE-181` ‡ and
 * `SEC-056` ‡ require ownership and relationship to be evaluated against
 * platform state and never against an inbound claim.
 */
final class TestRecord implements AuthorisationTarget
{
    /**
     * @param  list<ActorReference>  $parties
     */
    public function __construct(
        private readonly array $parties,
        private readonly ?ActorReference $entitlementSubject = null,
    ) {}

    /**
     * @return list<ActorReference>
     */
    public function partyReferences(): array
    {
        return $this->parties;
    }

    public function entitlementSubject(): ?ActorReference
    {
        return $this->entitlementSubject;
    }
}
