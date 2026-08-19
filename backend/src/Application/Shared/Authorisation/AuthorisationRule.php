<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

/**
 * What an operation requires of whoever asks for it.
 *
 * `SEC-059`: a rule is expressed **once** and evaluated identically for every
 * caller type. There is one rule per operation, and it says nothing about who is
 * asking beyond what they hold and how they relate to the record.
 *
 * A rule is built through a named constructor, so that *"anyone authenticated
 * may do this"* is a deliberate statement rather than the result of forgetting
 * to add a requirement. `SEC-055` ‡ refuses an operation with **no** stated
 * rule; it does not refuse an operation whose stated rule is permissive, and the
 * difference has to be visible in the register.
 */
final class AuthorisationRule
{
    private function __construct(
        private readonly ?Capability $capability,
        private readonly bool $requiresParty,
        private readonly ?RoleKind $requiresRoleOfKind,
        private readonly bool $altersEntitlement,
    ) {}

    /**
     * Any authenticated identity may perform this operation.
     *
     * Named, because `SEC-055` ‡ makes silence a refusal and this is not silence.
     */
    public static function permittingAnyAuthenticatedActor(): self
    {
        return new self(null, false, null, false);
    }

    /**
     * The actor must hold the capability, through a role (`FRD-FR-252` ‡).
     */
    public static function requiringCapability(Capability $capability): self
    {
        return new self($capability, false, null, false);
    }

    /**
     * The actor must be a party to the record (`SEC-066` ‡), evaluated against
     * platform state (`BE-181` ‡).
     */
    public static function requiringParty(): self
    {
        return new self(null, true, null, false);
    }

    /**
     * `SEC-061`: administrative capability is restricted by administrative role.
     * `FRD-FR-254`: the two kinds are restricted independently.
     */
    public static function requiringAdministrativeCapability(Capability $capability): self
    {
        return new self($capability, false, RoleKind::Administrative, false);
    }

    /**
     * Adds the party requirement to an existing rule.
     */
    public function andParty(): self
    {
        return new self($this->capability, true, $this->requiresRoleOfKind, $this->altersEntitlement);
    }

    /**
     * Marks the operation as one that alters entitlement.
     *
     * `SEC-058` ‡: **no operation shall permit a caller to alter their own
     * entitlement.** The authoriser enforces that whatever else this rule says —
     * a rule cannot opt out of it, in the same way that a state model cannot opt
     * out of a coded invariant (`BE-177` ‡).
     *
     * Nothing is marked yet. The operation this exists for is a role change
     * (`SEC-064`), and no such operation is built: `BAD-DEC-006` is open.
     *
     * Marking is a declaration, and a declaration can be forgotten. That is
     * inherent — nothing can know an operation alters entitlement without being
     * told — so it is also a review obligation on every new operation.
     */
    public function alteringEntitlement(): self
    {
        return new self($this->capability, $this->requiresParty, $this->requiresRoleOfKind, true);
    }

    public function capability(): ?Capability
    {
        return $this->capability;
    }

    public function requiresParty(): bool
    {
        return $this->requiresParty;
    }

    public function requiresRoleOfKind(): ?RoleKind
    {
        return $this->requiresRoleOfKind;
    }

    public function altersEntitlement(): bool
    {
        return $this->altersEntitlement;
    }

    /**
     * What this rule requires, for the register to be readable.
     */
    public function describe(): string
    {
        $parts = [];

        if ($this->requiresRoleOfKind !== null) {
            $parts[] = strtolower($this->requiresRoleOfKind->name).' role';
        }

        if ($this->capability !== null) {
            $parts[] = 'capability '.$this->capability->name();
        }

        if ($this->requiresParty) {
            $parts[] = 'party to the record';
        }

        if ($this->altersEntitlement) {
            $parts[] = 'alters entitlement (never the actor own)';
        }

        return $parts === [] ? 'any authenticated actor' : implode(', ', $parts);
    }
}
