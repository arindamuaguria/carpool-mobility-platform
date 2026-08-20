<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Domain\User\UserReference;

/**
 * Establish a session for an identity that has already been authenticated.
 *
 * `BE-042`: no transport representation, and — deliberately — **no
 * demonstration**. The credential is checked by whatever authenticated the
 * caller, and this carries only the reference that check produced. A command
 * carrying a demonstration would be a command that could be logged with one in
 * it, which `SEC-038` ‡ forbids.
 *
 * ## Why authentication is upstream
 *
 * `SEC-015` makes authentication the demonstration of possession of a verified
 * phone number, and CMP-DOC-10 §11.1 puts submitting a demonstration at
 * `POST /verifications/{id}/attempts` rather than at `POST /sessions` — which
 * realises `FRD-FR-016` alone: *"establish an authenticated session **on
 * successful authentication**."* The success is the precondition, not the work.
 *
 * That division is what lets this service state `SEC-051` ‡, `FRD-FR-018` and
 * `SEC-243` ‡ in one place for every caller that ever establishes a session,
 * rather than once per authentication mechanism.
 *
 * ## It is its own authorisation target
 *
 * `SEC-066` ‡ requires the actor to be a party to the record, and the party to a
 * session about to be established is the one identity it will be bound to
 * (`SEC-044` ‡). A caller cannot establish a session for somebody else, and the
 * rule rather than the code is what stops them.
 */
final class EstablishSessionCommand implements AuthorisationTarget, StateChangingCommand
{
    public function __construct(
        private readonly UserReference $user,
        private readonly IdempotencyKey $idempotencyKey,
    ) {}

    public function user(): UserReference
    {
        return $this->user;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    /**
     * `API-063` ‡ refuses a repeated key whose content differs, so the
     * fingerprint covers what the request asks: which identity a session is for.
     *
     * Nothing else is in it, because nothing else varies — an establishment is
     * fully determined by whose it is.
     */
    public function contentFingerprint(): string
    {
        return hash('sha256', $this->user->toString());
    }

    /**
     * @return list<ActorReference>
     */
    public function partyReferences(): array
    {
        return [ActorReference::fromString($this->user->toString())];
    }

    /**
     * `SEC-058` ‡ / `API-105` ‡: establishing a session grants no entitlement —
     * `SEC-045` ‡ keeps every claim out of it — so there is no entitlement
     * subject to guard.
     */
    public function entitlementSubject(): ?ActorReference
    {
        return null;
    }
}
