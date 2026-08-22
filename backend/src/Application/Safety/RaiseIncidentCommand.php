<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Application\User\AuthenticatedCaller;
use Cmp\Domain\User\UserReference;

/**
 * Raise a safety signal.
 *
 * **It carries nothing but who is raising it and the idempotency key**, and
 * that austerity is the requirement rather than an unfinished shape.
 * `API-164` ‡: *"The request shape for raising an incident shall be the
 * **minimum that permits recording**, so that a signal is never lost to a
 * validation failure on a non-essential field."* CMP-DOC-10 §12.3 lists *"rich
 * validation"* among what the safety surface deliberately omits, with the reason
 * in five words — *"a rejected signal is a lost signal."*
 *
 * So there is no severity, no category and no free-text note. Each would be a
 * field a caller could get wrong, and `FRD-FR-188` ‡ leaves no room for a
 * signal refused on a technicality. The circumstances are the platform's to
 * capture (`FRD-FR-186` ‡) and are recorded with their standing rather than
 * asked for.
 */
final class RaiseIncidentCommand implements AuthorisationTarget, StateChangingCommand
{
    private function __construct(
        private readonly UserReference $raiser,
        private readonly IdempotencyKey $idempotencyKey,
    ) {}

    public static function from(AuthenticatedCaller $caller, IdempotencyKey $idempotencyKey): self
    {
        return new self($caller->session()->user(), $idempotencyKey);
    }

    public function raiser(): UserReference
    {
        return $this->raiser;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    /**
     * `API-173` / `API-066` ‡: *"Idempotency shall apply, so that a repeated
     * raise under a poor connection produces one incident."*
     *
     * The fingerprint is the raiser, because that is all the request carries. A
     * caller repeating the same key gets the same incident back; a caller
     * raising a genuinely new signal sends a new key, which is what a client
     * does when the user acts again rather than when the network retried.
     */
    public function contentFingerprint(): string
    {
        return hash('sha256', $this->raiser->toString());
    }

    /**
     * `SEC-066` ‡ / `BE-181` ‡: the party is the identity the session is bound
     * to, read from platform state.
     *
     * @return list<ActorReference>
     */
    public function partyReferences(): array
    {
        return [ActorReference::fromString($this->raiser->toString())];
    }

    /**
     * `SEC-058` ‡: raising a safety incident alters nobody's entitlement.
     */
    public function entitlementSubject(): ?ActorReference
    {
        return null;
    }
}
