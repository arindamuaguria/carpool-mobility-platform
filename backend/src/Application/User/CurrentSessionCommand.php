<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Domain\User\Session;

/**
 * Act on the session the caller is holding.
 *
 * `BE-042`: a command carries **no transport representation** — no HTTP request,
 * no header, no raw token. The interface layer resolves the token to a
 * {@see Session} through {@see ResolveSession} and hands the session over, so the
 * same operation is invocable from the REST, administrative and safety callers
 * alike (`BE-043`, `BE-013`).
 *
 * It is also its own {@see AuthorisationTarget}. `SEC-066` ‡ requires the actor
 * to be a party to the record, and the party to a session is the one actor it was
 * bound to — `SEC-044` ‡ makes a session non-transferable, so there is exactly
 * one and no list to get wrong.
 *
 * `API-057` ‡: both operations change state, so both carry an idempotency key.
 * `API-062` ‡ then makes a repeat return the original outcome — which matters
 * most for refresh, where a lost response would otherwise leave a client with a
 * token it never received and a session it cannot use.
 */
final class CurrentSessionCommand implements AuthorisationTarget, StateChangingCommand
{
    public function __construct(
        private readonly Session $session,
        private readonly IdempotencyKey $idempotencyKey,
    ) {}

    public function session(): Session
    {
        return $this->session;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    /**
     * `API-063` ‡ refuses a repeated key whose content differs, so the
     * fingerprint has to cover what the request actually asks. Both operations
     * are fully determined by which session they act on.
     *
     * The **hash** identifies the session, not a raw token: `SEC-038` ‡ keeps a
     * token out of every stored and logged value, and an idempotency fingerprint
     * is stored.
     */
    public function contentFingerprint(): string
    {
        return hash('sha256', $this->session->tokenHash());
    }

    /**
     * `SEC-066` ‡ / `BE-181` ‡: evaluated against platform state. The session was
     * read from the store, so its user is state rather than an inbound claim.
     *
     * @return list<ActorReference>
     */
    public function partyReferences(): array
    {
        return [ActorReference::fromString($this->session->user()->toString())];
    }

    /**
     * `SEC-058` ‡ / `API-105` ‡: neither operation alters anybody's entitlement,
     * so there is no entitlement subject to guard.
     */
    public function entitlementSubject(): ?ActorReference
    {
        return null;
    }
}
