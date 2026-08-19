<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

/**
 * Why an authorisation was refused — **for the record, not for the caller**.
 *
 * `SEC-069` ‡ / `API-094` ‡: *"Absence and non-entitlement shall be
 * indistinguishable to a caller, so that existence cannot be probed."* A caller
 * who could tell *"no such record"* from *"not yours"* could enumerate the
 * platform by asking.
 *
 * `BE-182` pulls the other way, and both are satisfied: *"Authorisation refusal
 * shall be a distinct outcome from absence and from validation failure."* It is
 * distinct **internally** — that is this enum — and indistinguishable
 * **externally**, which is {@see AuthorisationRefusal}. `SEC-057` ‡ requires the
 * refusal to be recorded, and a record that could not say why would be of little
 * use to whoever reads it.
 */
enum AuthorisationRefusalCause
{
    /** `SEC-055` ‡: the operation has no stated rule, so it is refused. */
    case NoRuleStated;

    /** `SEC-058` ‡: the operation would alter the actor's own entitlement. */
    case WouldAlterOwnEntitlement;

    /** `FRD-FR-254` / `SEC-061`: no role of the required kind is held. */
    case RoleKindNotHeld;

    /** `FRD-FR-252` ‡: no role held grants the required capability. */
    case CapabilityNotHeld;

    /** `SEC-066` ‡: the actor is not a party to the record. */
    case NotAParty;

    public function describe(): string
    {
        return match ($this) {
            self::NoRuleStated => 'no authorisation rule is stated for the operation (SEC-055 ‡, deny by default)',
            self::WouldAlterOwnEntitlement => 'the operation would alter the actor own entitlement (SEC-058 ‡)',
            self::RoleKindNotHeld => 'no role of the required kind is held (FRD-FR-254, SEC-061)',
            self::CapabilityNotHeld => 'no role held grants the required capability (FRD-FR-252 ‡)',
            self::NotAParty => 'the actor is not a party to the record (SEC-066 ‡)',
        };
    }
}
