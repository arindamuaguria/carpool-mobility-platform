<?php

declare(strict_types=1);

namespace Cmp\Application\User;

/**
 * Makes session tokens, and reduces one to what the store holds.
 *
 * `SEC-035` ‡: generated with a cryptographically secure random source and
 * carrying **sufficient entropy that guessing is infeasible**. `SEC-036` ‡: the
 * platform stores a hash of the token, never the token.
 *
 * ## Why this is not `SEC-028` ‡'s memory-hard hash
 *
 * CMP-DOC-13 §14.2 separates the two constructions explicitly:
 *
 * | Purpose | Requirement | Statement |
 * |---|---|---|
 * | Authentication material | Memory-hard, salted, tunable password hash | `SEC-028` |
 * | Session token storage | **One-way hash of a high-entropy random token** | `SEC-036` |
 *
 * A memory-hard hash exists to make guessing a **low-entropy** secret expensive —
 * a password a person chose. A session token is not chosen by anybody and
 * `SEC-035` ‡ gives it enough entropy that guessing is infeasible before any cost
 * is added, so a fast one-way hash is what §14.2 asks for and what validation on
 * **every request** (`SEC-042`) can afford.
 *
 * This is why session work is not blocked on `SEC-031`. Those cost parameters are
 * `SEC-028` ‡'s, they are `[TBD – Technical Decision Required]`, and nothing here
 * needs them.
 *
 * ## No salt, and that is not an omission
 *
 * `SEC-029` ‡ requires a unique salt for **authentication material**. A salt
 * defends against a precomputed table over a small input space; a token from
 * `SEC-035` ‡'s source has no small input space, and a salted token hash could
 * not be looked up — `SEC-042` needs the hash of the presented token to find the
 * row, which a per-row salt would prevent.
 */
interface HashesSessionTokens
{
    /**
     * A new token, for issuing once and never storing.
     *
     * `SEC-038` ‡: the return value reaches the caller's response and nothing
     * else — not a log, not a diagnostic record, not an error message.
     */
    public function generate(): string;

    /**
     * What the store holds for a token (`SEC-036` ‡).
     *
     * Deterministic, because `SEC-042` looks a session up by it.
     */
    public function hash(string $token): string;
}
