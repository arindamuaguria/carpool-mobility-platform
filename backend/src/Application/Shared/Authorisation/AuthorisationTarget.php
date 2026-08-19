<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

use Cmp\Application\Shared\Idempotency\ActorReference;

/**
 * The record an operation acts on, as the platform holds it.
 *
 * `BE-181` ‡ / `SEC-056` ‡: *"Ownership and relationship shall be evaluated
 * against platform state, never against an inbound claim."* A target is
 * therefore an object the platform loaded, not a set of identifiers a caller
 * sent — a caller who could name the parties to a record could name themselves
 * among them.
 *
 * `SEC-066` ‡: a user accesses only records to which they are a party. That is
 * what {@see partyReferences()} answers, and it answers it from state.
 */
interface AuthorisationTarget
{
    /**
     * Everyone the platform records as a party to this record.
     *
     * @return list<ActorReference>
     */
    public function partyReferences(): array;

    /**
     * Whose entitlement this operation would alter, where it alters one.
     *
     * `SEC-058` ‡: **no operation permits a caller to alter their own
     * entitlement.** The authoriser refuses when this is the acting identity,
     * whatever the declared rule says.
     */
    public function entitlementSubject(): ?ActorReference;
}
