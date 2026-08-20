<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use Cmp\Domain\Shared\Time\Instant;
use InvalidArgumentException;

/**
 * An established session, as the platform holds it.
 *
 * Part of the `User` aggregate's boundary — CMP-DOC-11 §6.10 lists `op_sessions`
 * among the tables realising `User`, and `SEC-044` ‡ binds a session to the actor
 * it authenticated and makes it non-transferable, which is what makes it the
 * user's rather than a thing of its own.
 *
 * ## It holds a hash, never a token
 *
 * `SEC-036` ‡: *"The platform shall store a hash of the token, never the
 * token."* The raw token exists only in the request that carried it and in the
 * response that first issued it; nothing here can leak one because nothing here
 * has one. `SEC-038` ‡ then keeps even the hash out of a response, a log or a
 * diagnostic record — which is why {@see tokenHash()} is the only accessor and no
 * `__toString()` exists to make it accidental.
 *
 * ## It answers when it was established, not when it expires
 *
 * `SEC-039` ‡ makes the session lifetime **policy configuration**, and a Domain
 * entity does not read policy — `BE-002` keeps the framework out and `BE-172` ‡
 * keeps a policy value from relaxing a rule. So the bound is passed in
 * ({@see isUsableAt()}) rather than held, and `CMP-IMP-051` stores no `expires_at`
 * for the same reason: `BE-170` invalidates the policy cache on change, and an
 * expiry frozen at establishment would make a shortened bound not apply to the
 * sessions it was shortened for.
 *
 * ## `SEC-045` ‡ — it carries no authorisation claim
 *
 * There is no role, no capability and no entitlement here, and there is nowhere
 * to put one. `SEC-045` ‡ requires entitlement to be evaluated against platform
 * state **on every request**, and a session that could carry a claim is a session
 * that could carry a stale one.
 */
final class Session
{
    private function __construct(
        private readonly UserReference $user,
        private readonly string $tokenHash,
        private readonly Instant $establishedAt,
        private ?Instant $terminatedAt,
    ) {}

    public static function establish(UserReference $user, string $tokenHash, Instant $at): self
    {
        return new self($user, self::assertHash($tokenHash), $at, null);
    }

    /**
     * A session read back from the store.
     */
    public static function reconstitute(
        UserReference $user,
        string $tokenHash,
        Instant $establishedAt,
        ?Instant $terminatedAt,
    ): self {
        return new self($user, self::assertHash($tokenHash), $establishedAt, $terminatedAt);
    }

    /**
     * `SEC-040` ‡ / `DB-044` ‡: recorded as terminated, never removed.
     *
     * Idempotent, and the first instant wins. A second termination that moved the
     * instant would rewrite when the session stopped being usable, and `DB-044` ‡
     * keeps the record so that reuse is **detectable** — which needs the original
     * time, not the time somebody asked again.
     */
    public function terminate(Instant $at): void
    {
        $this->terminatedAt ??= $at;
    }

    public function isTerminated(): bool
    {
        return $this->terminatedAt !== null;
    }

    /**
     * `SEC-039` ‡, given the bound.
     *
     * Measured from establishment, which is how CMP-DOC-13 v0.4 states the
     * twenty-four hours. A session established exactly `$lifetimeInSeconds` ago
     * has expired: the bound is the length of the window, not the first instant
     * outside it.
     */
    public function hasExpiredAt(Instant $now, int $lifetimeInSeconds): bool
    {
        if ($lifetimeInSeconds < 1) {
            throw new InvalidArgumentException(
                'SEC-039 ‡: a session lifetime is a positive bound. A bound of zero would expire every session '
                .'at the instant it was established, which is not a bounded lifetime but an absent one.'
            );
        }

        $elapsed = $now->toDateTime()->getTimestamp() - $this->establishedAt->toDateTime()->getTimestamp();

        return $elapsed >= $lifetimeInSeconds;
    }

    /**
     * Both halves of `SEC-048` ‡'s first two cases, asked together.
     *
     * The third — an unknown token — is not a `Session` at all, so it cannot be
     * asked here; the resolver answers it by finding nothing.
     */
    public function isUsableAt(Instant $now, int $lifetimeInSeconds): bool
    {
        return ! $this->isTerminated() && ! $this->hasExpiredAt($now, $lifetimeInSeconds);
    }

    public function user(): UserReference
    {
        return $this->user;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function establishedAt(): Instant
    {
        return $this->establishedAt;
    }

    public function terminatedAt(): ?Instant
    {
        return $this->terminatedAt;
    }

    private static function assertHash(string $tokenHash): string
    {
        if (trim($tokenHash) === '') {
            throw new InvalidArgumentException(
                'SEC-036 ‡: a session is identified by the hash of its token, and an empty hash identifies every session.'
            );
        }

        return $tokenHash;
    }
}
