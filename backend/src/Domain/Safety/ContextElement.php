<?php

declare(strict_types=1);

namespace Cmp\Domain\Safety;

/**
 * `FRD-FR-186` ‡'s context, as a closed register.
 *
 * *"The system shall capture, with each safety incident, the raising user, the
 * trip, the location at the time, the vehicle and the co-travellers involved."*
 * `BRD-REQ-111` is the business statement and `BRD-SR-028` is the reason a
 * responder gives for needing it — *"who raised an alarm, where they are, which
 * trip they are on, and who they are with."*
 *
 * Five elements are named. The **raising user** is not here, because it is never
 * in question: `SEC-044` ‡ binds a session to exactly one actor, so the platform
 * knows who raised an incident without asking and without a standing. The other
 * four each carry one.
 *
 * ## Closed, and checked
 *
 * Every incident carries a standing for **every** case here — see {@see
 * IncidentContext}. That is what makes `FRD-FR-186` ‡ answerable: an element
 * quietly omitted would be indistinguishable from one nobody thought about, and
 * `DB-078` ‡ exists precisely so the difference is visible.
 *
 * Adding a case here therefore changes what every incident must say, which is
 * the intended cost: a sixth element is a change to what the platform captures,
 * not a field somebody adds to a form.
 */
enum ContextElement: string
{
    case Trip = 'trip';

    case Vehicle = 'vehicle';

    case CoTravellers = 'co_travellers';

    case Location = 'location';

    /**
     * The column on `op_safety_incidents` holding this element's standing.
     */
    public function standingColumn(): string
    {
        return $this->value.'_standing';
    }
}
