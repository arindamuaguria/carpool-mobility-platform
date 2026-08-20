<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use Cmp\Domain\Shared\Time\Instant;

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
 * There **is** a count of usable sessions ({@see usableCountFor()}), added when
 * `SEC-243` ‡ settled what the limit does. It is a count and not a list: nothing
 * needs to know which sessions a user holds, and a method returning them would be
 * a way for one caller to learn about another's devices.
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
     * How many sessions this user currently holds that would still serve
     * (`SEC-049`, counted for `SEC-243` ‡).
     *
     * **Usable, not merely un-terminated.** `SEC-039` ‡ bounds a session's
     * lifetime and `DB-044` ‡ keeps a terminated row forever, so a count of rows
     * would grow without limit and a count of un-terminated rows would include
     * sessions that expired months ago. Either would refuse establishment to a
     * user holding nothing at all.
     *
     * The lifetime is passed in for the same reason {@see Session::isUsableAt()}
     * takes it: `BE-002` keeps a Domain contract from reading policy, and
     * `BE-170` requires a shortened bound to apply to sessions already
     * established — which it cannot if the bound was frozen anywhere.
     *
     * @param  int  $lifetimeInSeconds  `SEC-039` ‡'s bound, measured from
     *                                  establishment
     */
    public function usableCountFor(UserReference $user, Instant $now, int $lifetimeInSeconds): int;

    /**
     * Writes an established or terminated session.
     *
     * `BE-047` ‡ reserves the transaction for the application layer, so this
     * opens none: it is called inside the one that the operation it belongs to
     * opened.
     */
    public function save(Session $session): void;
}
