<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

/**
 * How the platform holds `FRD-FR-184`'s record.
 *
 * `BE-037`: declared in the Domain, and every method returns a domain object or
 * one of the permitted scalars. {@see NominatedContacts} is what makes that
 * possible — an array of contacts would be the row shape leaking through a
 * contract that promised not to leak it.
 *
 * ## The whole set, both ways
 *
 * `UC-048` A1 records a set, so the set is the unit of work. Reading one
 * contact in isolation is never what an operation needs: nominating checks the
 * set for the number, amending checks it for a collision, and removing checks it
 * for the reference — each is a question about the set, and asking the database
 * three narrower questions would put the set rule in the adapter where `BE-010`
 * forbids it.
 */
interface EmergencyContactRepository
{
    /**
     * The contacts this user has nominated, empty where they have nominated
     * none.
     *
     * Never null. A user with no contacts and a user who has removed all of
     * theirs are the same state, and `NominatedContacts::noneFor()` says so
     * without a caller having to test for it.
     */
    public function forUser(UserReference $user): NominatedContacts;

    /**
     * Make the stored set match this one.
     *
     * `FRD-FR-184`. The implementation writes the difference; the contract is
     * that afterwards {@see forUser()} returns this. `DB-142` ‡'s pattern
     * applies — the unique constraint decides a race and the loser rolls back,
     * because there is no locking read available across a set this small that
     * would be cheaper than the constraint that already exists.
     */
    public function save(NominatedContacts $contacts): void;
}
