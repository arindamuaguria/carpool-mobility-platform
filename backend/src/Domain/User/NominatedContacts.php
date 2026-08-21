<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use Cmp\Domain\Shared\Refusal\BusinessRefusal;

/**
 * One user's emergency contacts, as a set.
 *
 * `UC-048` A1 states it exactly: *"More than one may be nominated; the platform
 * records the **set**."* That sentence is this type — every rule about what the
 * collection may hold lives here and nowhere else, which is `BE-010`'s
 * requirement that one business rule reside in one Domain component.
 *
 * `BE-037` is the other reason it exists: a repository returns domain objects,
 * never an array of them, so the collection a repository hands back has to be a
 * type in the Domain.
 *
 * ## No bound, and that is not an oversight
 *
 * `FRD-FR-181` permits *"one or more"* and **no document bounds the set**. None
 * is invented here: a maximum would be a decision about how many people a user
 * may ask for help, taken in a value object where nobody would look for it, and
 * `NFR-057`/`API-127` already make request-rate limiting the platform's stated
 * answer to volume. The absence is recorded in `CC-044` with what would decide
 * it, rather than left for a reader to infer from the lack of a constant.
 *
 * ## Nothing here reaches a contact
 *
 * There is no `notify()`, no `highestPriority()` and no ordering. `BE-195` ‡
 * would attempt notification *"through the highest-priority family"* — and
 * `FRD-GAP-020` blocks every part of that on `BAD-DEC-011`, so a priority order
 * here would be the first half of a mechanism whose second half must not exist.
 * `UC-048` accepts the platform holding contacts *"only because nothing is sent
 * to them"*.
 */
final class NominatedContacts
{
    /**
     * @param  list<EmergencyContact>  $contacts
     */
    private function __construct(
        private readonly UserReference $user,
        private readonly array $contacts,
    ) {}

    public static function of(UserReference $user, EmergencyContact ...$contacts): self
    {
        return new self($user, array_values($contacts));
    }

    public static function noneFor(UserReference $user): self
    {
        return new self($user, []);
    }

    public function user(): UserReference
    {
        return $this->user;
    }

    /**
     * @return list<EmergencyContact>
     */
    public function all(): array
    {
        return $this->contacts;
    }

    public function count(): int
    {
        return count($this->contacts);
    }

    public function isEmpty(): bool
    {
        return $this->contacts === [];
    }

    /**
     * The nomination this reference names, or null where the set holds none.
     *
     * Null rather than a raise, because the caller decides what absence means:
     * `SEC-069` ‡ requires it to be indistinguishable from a contact belonging to
     * somebody else, and both arrive here as the same null.
     */
    public function referenced(EmergencyContactReference $reference): ?EmergencyContact
    {
        foreach ($this->contacts as $contact) {
            if ($contact->reference()->equals($reference)) {
                return $contact;
            }
        }

        return null;
    }

    public function holds(PhoneNumber $number): bool
    {
        foreach ($this->contacts as $contact) {
            if ($contact->number()->equals($number)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The set with one more nomination in it.
     *
     * `FRD-FR-181`. The set rule is enforced here rather than only by the unique
     * constraint, because the refusal has to name a reason (`FRD-FR-183`,
     * `API-131`) and a constraint violation names a column. The constraint is
     * still what decides a race — `DB-142` ‡'s pattern — and this is what decides
     * the ordinary case with an answer the caller can read.
     *
     * @throws BusinessRefusal where the number is already nominated
     */
    public function with(EmergencyContact $contact): self
    {
        if ($this->holds($contact->number())) {
            throw new BusinessRefusal(EmergencyContactRefusal::AlreadyNominated);
        }

        return new self($this->user, [...$this->contacts, $contact]);
    }

    /**
     * The set with one nomination amended.
     *
     * `FRD-FR-182`. Amending a contact to a number another contact already holds
     * is the same collision as nominating it twice, and is refused the same way —
     * amending is not a route around the set rule.
     *
     * @throws BusinessRefusal where the reference names nothing in this set, or
     *                         the new number is held by a different contact
     */
    public function amending(EmergencyContactReference $reference, PhoneNumber $number, ?ContactLabel $label): self
    {
        $existing = $this->referenced($reference);

        if ($existing === null) {
            throw new BusinessRefusal(EmergencyContactRefusal::NotNominated);
        }

        foreach ($this->contacts as $contact) {
            if (! $contact->reference()->equals($reference) && $contact->number()->equals($number)) {
                throw new BusinessRefusal(EmergencyContactRefusal::AlreadyNominated);
            }
        }

        $amended = $existing->amendedTo($number, $label);

        return new self($this->user, array_values(array_map(
            static fn (EmergencyContact $contact): EmergencyContact => $contact->reference()->equals($reference)
                ? $amended
                : $contact,
            $this->contacts,
        )));
    }

    /**
     * The set with one nomination removed.
     *
     * `FRD-FR-182`. Removing one that is not there is refused rather than
     * treated as already-done: `API-062` ‡ makes a **replayed** request return
     * its original outcome, and that is the idempotency key's job, not a
     * pretence here that a reference the caller invented was once real.
     *
     * @throws BusinessRefusal where the reference names nothing in this set
     */
    public function without(EmergencyContactReference $reference): self
    {
        if ($this->referenced($reference) === null) {
            throw new BusinessRefusal(EmergencyContactRefusal::NotNominated);
        }

        return new self($this->user, array_values(array_filter(
            $this->contacts,
            static fn (EmergencyContact $contact): bool => ! $contact->reference()->equals($reference),
        )));
    }
}
