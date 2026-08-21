<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\Shared\Refusal\RefusalReason;

/**
 * Why the platform declines to record a nomination.
 *
 * `FRD-FR-183` requires the platform to *"state why a contact is unusable"* and
 * `API-131` requires the reason to reach the caller. `AADR-14` makes a reason an
 * **identifier**, not free text, and `API-081` ‡ makes the identifier stable —
 * so each case here is registered in `ReasonIdentifiers`.
 *
 * ## Two cases, and why a malformed number is not among them
 *
 * A number the platform cannot parse is a **shape** failure: the caller sent
 * something they can correct without knowing anything about platform state, and
 * `API-087` puts that in the invalid-request branch. {@see PhoneNumber} and
 * {@see ContactLabel} raise for it, and `NominateEmergencyContact` converts.
 * These two are refusals decided **against state** — which is the other branch.
 */
enum EmergencyContactRefusal: string implements RefusalReason
{
    /**
     * `UC-048` A1: *"the platform records the **set**."*
     *
     * A set holds a number once. The second nomination is refused rather than
     * silently absorbed, because absorbing it would discard a label the user
     * meant to set and leave them believing they had set it — the same objection
     * `AADR-06` raised to ignore-on-input, and the reason `FRD-FR-024` requires a
     * rejected amendment to say so rather than pass quietly.
     */
    case AlreadyNominated = 'emergency_contact.already_nominated';

    /**
     * The reference names no contact in **the caller's own set**.
     *
     * `SEC-069` ‡ and `API-094` ‡ require absence and non-entitlement to be
     * indistinguishable. They are here by construction rather than by care: the
     * service looks only within the caller's set, so a reference belonging to
     * another user and a reference belonging to nobody produce the same lookup
     * and the same answer. There is deliberately no second identifier telling a
     * caller which.
     */
    case NotNominated = 'emergency_contact.not_nominated';

    public function identifier(): string
    {
        return $this->value;
    }

    public function defaultText(): string
    {
        return match ($this) {
            self::AlreadyNominated => 'That number is already one of your emergency contacts.',
            self::NotNominated => 'That is not one of your emergency contacts.',
        };
    }

    /**
     * Both are conflicts with state the caller can see and act on.
     *
     * `RefusalKind::StateConflict` maps to `409`. Neither is a rule declining
     * something well-formed and permitted — the caller can remove the existing
     * nomination, or name one that exists, and try again.
     */
    public function kind(): RefusalKind
    {
        return RefusalKind::StateConflict;
    }
}
