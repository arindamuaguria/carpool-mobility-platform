<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

/**
 * Where sessions are read and written.
 *
 * `BE-037`: declared in `Domain` and returning domain objects. `SEC-041` ‡ is why
 * one exists at all — *"session state shall be held in the store and not in
 * application-instance memory"* — so that a session survives the instance that
 * established it and `ARCH-138`'s horizontal scaling does not depend on affinity.
 *
 * ## Looked up by hash, because that is what a request carries
 *
 * `SEC-042`: *"Session validation shall be a hash-and-lookup against the store on
 * every request."* Every request, not once per session — `SEC-045` ‡ keeps
 * entitlement out of the token, so there is nothing to trust between requests.
 *
 * ## What is deliberately not here
 *
 * There is no `deleteFor()` and no `prune()`. `DB-044` ‡ records a terminated
 * session and **does not remove it**, so that reuse is detectable rather than
 * merely impossible, and `SEC-047` retains the record for the period `DB-169`
 * sets — which is policy configuration and unset. A repository method that could
 * remove one would be a way to lose the detection.
 *
 * There is no `terminateAllFor()` either, though `SEC-046` requires terminating
 * all of a user's sessions to be possible as a single operation. Nothing invokes
 * it: it is *"for use when compromise is suspected"*, which is an operator
 * capability, and `BAD-DEC-006` leaves the administrative role set undecided
 * (`ADM-168`). It arrives with the surface that calls it.
 *
 * And there is no count of active sessions, which `SEC-049`'s limit of three
 * would need. That limit bites at **establishment**, and establishment is
 * blocked: no statement says what the platform does when the limit is reached.
 */
interface SessionRepository
{
    /**
     * The session a token hash identifies, or null where none does.
     *
     * Null for an unknown hash rather than an exception, because `SEC-048` ‡
     * makes an unknown token indistinguishable from a terminated or expired one
     * **to a caller** — and a contract that raised here would make the three
     * different at the point where the code decides what to say.
     */
    public function forTokenHash(string $tokenHash): ?Session;

    /**
     * Writes an established or terminated session.
     *
     * `BE-047` ‡ reserves the transaction for the application layer, so this
     * opens none: it is called inside the one that the operation it belongs to
     * opened.
     */
    public function save(Session $session): void;
}
