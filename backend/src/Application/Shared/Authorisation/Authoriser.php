<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

/**
 * The one authorisation evaluation (`SADR-06`).
 *
 * `SEC-053` ‡ / `BE-179` ‡ / `ARCH-031`: evaluated in the **application layer**,
 * on every operation and every caller — client, operator, worker, safety
 * surface. `SEC-054` ‡ / `BE-180` ‡: never in transport middleware alone.
 * `SEC-009` ‡: an operator request traverses the same evaluation as a client
 * request.
 *
 * `SADR-06` rejected separate administrative authorisation in its own words:
 * *"two paths diverge, and the one used less is the one that rots."*
 *
 * **This method returns void.** It permits or it raises; it cannot hand back an
 * exemption, and nothing it produces reaches the domain. That is `SEC-010` ‡ and
 * `SEC-062` ‡ made structural — *"an operator gains capability, never
 * exemption"* — because a permitted operation still runs through every domain
 * invariant afterwards (`BE-044`).
 *
 * The order of checks is deliberate. Deny-by-default first, so an unstated
 * operation is refused before anything else is examined; then the absolute rule
 * of `SEC-058` ‡, which no stated rule may opt out of; then what the rule
 * requires.
 */
final class Authoriser
{
    public function __construct(
        private readonly AuthorisationPolicy $policy,
        private readonly RecordsAuthorisationRefusals $refusals,
    ) {}

    /**
     * @throws AuthorisationRefused when the actor may not perform the operation
     */
    public function authorise(Operation $operation, Actor $actor, ?AuthorisationTarget $target = null): void
    {
        $rule = $this->policy->ruleFor($operation);

        // SEC-055 ‡: an operation with no stated rule is refused. SADR-06 accepts
        // the cost: "Every operation needs an explicit rule before it works at
        // all."
        if ($rule === null) {
            $this->refuse($operation, $actor, AuthorisationRefusalCause::NoRuleStated);
        }

        // SEC-058 ‡: no operation permits a caller to alter their own
        // entitlement. Checked before the rule, because no rule may opt out of
        // it — the same asymmetry as a coded invariant against a state model
        // (BE-177 ‡).
        if ($rule->altersEntitlement()
            && $target !== null
            && $target->entitlementSubject() !== null
            && $actor->is($target->entitlementSubject())) {
            $this->refuse($operation, $actor, AuthorisationRefusalCause::WouldAlterOwnEntitlement);
        }

        // FRD-FR-254 / SEC-061: administrative capability is restricted by
        // administrative role, and the two kinds are restricted independently.
        $kind = $rule->requiresRoleOfKind();

        if ($kind !== null && ! $actor->holdsRoleOfKind($kind)) {
            $this->refuse($operation, $actor, AuthorisationRefusalCause::RoleKindNotHeld);
        }

        // FRD-FR-251 ‡ / FRD-FR-252 ‡: the roles held are determined first, and
        // the action is permitted only where a role held allows it.
        $capability = $rule->capability();

        if ($capability !== null && ! $actor->holds($capability)) {
            $this->refuse($operation, $actor, AuthorisationRefusalCause::CapabilityNotHeld);
        }

        // SEC-066 ‡ / BE-181 ‡: party to the record, evaluated against platform
        // state. A rule requiring a relationship with no record to evaluate it
        // against is refused — an unverifiable requirement is not a met one.
        if ($rule->requiresParty() && ! $this->isParty($actor, $target)) {
            $this->refuse($operation, $actor, AuthorisationRefusalCause::NotAParty);
        }
    }

    /**
     * Whether the operation would be permitted, without performing it.
     *
     * Runs the same evaluation, so it cannot report as available something
     * {@see authorise()} would then refuse. A refusal recorded here is a genuine
     * refusal (`SEC-057` ‡) — asking is a decision the platform made.
     */
    public function permits(Operation $operation, Actor $actor, ?AuthorisationTarget $target = null): bool
    {
        try {
            $this->authorise($operation, $actor, $target);
        } catch (AuthorisationRefused) {
            return false;
        }

        return true;
    }

    private function isParty(Actor $actor, ?AuthorisationTarget $target): bool
    {
        if ($target === null) {
            return false;
        }

        foreach ($target->partyReferences() as $party) {
            if ($actor->is($party)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `SEC-057` ‡ / `BE-183` / `API-088` ‡: **every** refused authorisation is
     * recorded. Recorded first, then raised, so that a refusal cannot be
     * reported without its record existing.
     *
     * @throws AuthorisationRefused
     */
    private function refuse(Operation $operation, Actor $actor, AuthorisationRefusalCause $cause): never
    {
        $this->refusals->record($operation, $actor, $cause);

        throw AuthorisationRefused::because($operation, $cause);
    }
}
