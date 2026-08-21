<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Domain\User\UserReference;

/**
 * `SEC-206` ‡'s fifth conduct — a token was presented that will not serve.
 *
 * ## Where the record goes, and why it is not one place
 *
 * `SEC-203` ‡: *"security events concerning **an actor's conduct** shall be
 * evidential records."* `SEC-204`: *"security events concerning **the platform's
 * own health** shall be operational logs."* A session anomaly is one or the other
 * depending on something the platform can actually know — **whose session it
 * was**:
 *
 * | Cause | Actor | Where |
 * |---|---|---|
 * | Terminated | Known — the row is retained (`DB-044` ‡) | Evidential |
 * | Expired | Known — the row is retained | Evidential |
 * | Unknown | **Nobody** | Operational |
 *
 * A terminated token presented again is conduct, and `DB-044` ‡ says exactly why
 * the row is kept: *"so that reuse is **detectable** rather than merely
 * impossible."* This is the detection. `SEC-207` ‡ requires the record to carry an
 * actor, and here it can.
 *
 * An **unknown** token names nobody. `BE-107` ‡ refuses an evidential record that
 * cannot say what it is about, and inventing an actor to satisfy the field would
 * be inventing the thing the record exists to state. It is also, in practice, as
 * likely to be a stale client or a scanner as an attacker — which is the
 * platform's own health, and `SEC-204`'s. So it is logged operationally, with no
 * actor, and no evidential record is written.
 *
 * ## What neither carries
 *
 * `SEC-208` ‡ and `SEC-038` ‡: **not the token, and not its hash.** The hash is
 * what the store matches on, so a record holding it holds the means to find the
 * session. `SEC-210` makes that exclusion a property of this contract — there is
 * no parameter here to pass one in.
 *
 * ## What a caller still cannot tell
 *
 * `SEC-048` ‡ keeps terminated, expired and unknown indistinguishable **to a
 * caller**, and nothing here changes that: {@see SessionRefusal} still has one
 * case and the response is byte-identical. The distinction lives in
 * {@see SessionRefusalCause}, which exists for this — an operator reading the
 * record needs the difference that a caller must not have.
 */
interface RecordsSessionAnomalies
{
    /**
     * A token was presented and refused.
     *
     * @param  UserReference|null  $user  whose session it was, where the platform
     *                                    knows — null for an unknown token, which
     *                                    names nobody
     */
    public function record(SessionRefusalCause $cause, ?UserReference $user): void;
}
