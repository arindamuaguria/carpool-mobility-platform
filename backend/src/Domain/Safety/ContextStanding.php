<?php

declare(strict_types=1);

namespace Cmp\Domain\Safety;

/**
 * `DB-078` ‡ — three states, and the middle one is the whole point.
 *
 * *"Missing context shall be recorded as missing, and shall be distinguishable
 * from context that was **absent in fact**."*
 *
 * `UC-051` E3 is the case that forces it: *"Signal raised outside an active
 * trip. The incident is still recorded, with no trip context."* An incident with
 * no trip because there was no trip is a complete record. An incident with no
 * trip because the platform could not look one up is an **incomplete** one, and
 * an operator reading the queue has to be able to tell which they are holding —
 * one means *they were not travelling*, the other means *we do not know*.
 *
 * A two-state field would collapse those into `null`, and `FRD-FR-187` ‡'s
 * *"marking the missing context as unavailable"* would have nothing to mark
 * with.
 */
enum ContextStanding: string
{
    /**
     * The platform holds this element and it is on the record.
     */
    case Recorded = 'recorded';

    /**
     * There was none. `UC-051` E3 — no trip, because the raiser was not
     * travelling. A complete answer, not a missing one.
     */
    case AbsentInFact = 'absent';

    /**
     * The platform could not obtain it. `FRD-FR-187` ‡ requires the incident
     * recorded anyway, with this marking, and `BE-194` ‡ requires recording to
     * succeed where any non-essential dependency is unavailable.
     *
     * **Every element stands here today**, and each for a reason recorded
     * against it rather than for want of trying: `Trip` and co-travellers need
     * aggregates `BE-017` names and nothing has built, `Vehicle` is blocked at
     * `CC-035`, and location is FEAT-019's under `BAD-DEC-021` and `FRD-OQ-009`.
     */
    case Unavailable = 'unavailable';

    public function isKnown(): bool
    {
        return $this !== self::Unavailable;
    }
}
