<?php

declare(strict_types=1);

namespace Cmp\Application\User;

/**
 * Reduces a demonstration to what `op_user_credentials` holds, and checks one
 * back.
 *
 * `SEC-028` ‡: authentication material is stored under a **memory-hard, salted,
 * tunable password hash**. `SEC-244` ‡ fixes that construction as **Argon2id**.
 * `DB-042` ‡ puts the result in `op_user_credentials` and never on `op_users`,
 * and `SEC-033` ‡ requires that nothing the platform stores can be presented back
 * to it as a credential — which is what makes the reduction one-way rather than
 * reversible.
 *
 * ## Why this is not {@see HashesSessionTokens}
 *
 * CMP-DOC-13 §14.2 lists two constructions and separates them by purpose. A
 * session token is high-entropy and nobody chose it (`SEC-035` ‡), so a fast
 * one-way hash is right and cost would only slow `SEC-042`'s per-request lookup.
 * A demonstration is short and a person reads it off a screen, so its input space
 * is small and cost is the entire defence. Two purposes, two constructions, and
 * the platform holds both rather than choosing between them.
 *
 * ## Three operations, and no fourth
 *
 * There is no way to read a stored value back out, and no way to compare two
 * stored values. `SEC-033` ‡ and `NFR-053` between them mean nothing that leaves
 * this interface can be replayed at the platform, so nothing here returns
 * anything a caller could present.
 */
interface HashesAuthenticationMaterial
{
    /**
     * What the store holds for a demonstration (`SEC-028` ‡).
     *
     * The result carries its own unique salt (`SEC-029` ‡) and its own cost
     * parameters (`SEC-032` ‡), so two calls with the same input return
     * different values and neither is a lookup key.
     *
     * @throws HashCostBelowFloor where the configured cost is beneath
     *                            `SEC-244` ‡'s floor
     */
    public function hash(string $material): string;

    /**
     * Whether a presented demonstration is the one that was stored.
     *
     * `SEC-020` ‡: compared in **constant time with respect to its content**, so
     * that how long the answer takes says nothing about how much of the
     * demonstration was right.
     *
     * Returns `false` for a stored value it cannot read rather than raising: a
     * malformed row is a refusal to the caller either way, and `SEC-021` and
     * `FRD-FR-015` require every refusal on this path to look alike.
     */
    public function matches(string $material, string $stored): bool;

    /**
     * Whether a stored value was produced under weaker parameters than the
     * current setting (`SEC-032` ‡).
     *
     * The re-hash itself happens on **next successful authentication**, which is
     * the only moment the platform holds the material in the clear — a stored
     * value cannot be strengthened without it.
     */
    public function needsRehash(string $stored): bool;
}
