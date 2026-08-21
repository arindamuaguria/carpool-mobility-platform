<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Domain\User\ContactLabel;
use Cmp\Domain\User\UserReference;

/**
 * What the caller is asking to do with their emergency contacts.
 *
 * One command for the four operations of `UC-048`, with a named constructor
 * each. They share a target — *the caller's own set* — and differ only in which
 * details they carry, so four classes would be four copies of
 * {@see partyReferences()} and one place for them to drift apart.
 *
 * `BE-042`: the details arrive as **strings**, not as {@see
 * \Cmp\Domain\User\PhoneNumber} or {@see ContactLabel}. The
 * interface layer cannot build a Domain type (`BE-002`), and it must not decide
 * that a value is well-formed either — `FRD-FR-183` makes *"state why a contact
 * is unusable"* a platform behaviour with a reason the caller reads, so the
 * parse belongs where the refusal is built.
 */
final class EmergencyContactCommand implements AuthorisationTarget, StateChangingCommand
{
    private function __construct(
        private readonly UserReference $user,
        private readonly IdempotencyKey $idempotencyKey,
        private readonly ?string $reference,
        private readonly ?string $number,
        private readonly ?string $label,
    ) {}

    /**
     * `FRD-FR-181`, the read half — the set as the platform holds it.
     */
    public static function toRead(AuthenticatedCaller $caller, IdempotencyKey $idempotencyKey): self
    {
        return new self($caller->session()->user(), $idempotencyKey, null, null, null);
    }

    /**
     * `FRD-FR-181` — nominate.
     */
    public static function toNominate(
        AuthenticatedCaller $caller,
        IdempotencyKey $idempotencyKey,
        ?string $number,
        ?string $label,
    ): self {
        return new self($caller->session()->user(), $idempotencyKey, null, $number, $label);
    }

    /**
     * `FRD-FR-182` — amend.
     */
    public static function toAmend(
        AuthenticatedCaller $caller,
        IdempotencyKey $idempotencyKey,
        string $reference,
        ?string $number,
        ?string $label,
    ): self {
        return new self($caller->session()->user(), $idempotencyKey, $reference, $number, $label);
    }

    /**
     * `FRD-FR-182` — remove.
     */
    public static function toRemove(
        AuthenticatedCaller $caller,
        IdempotencyKey $idempotencyKey,
        string $reference,
    ): self {
        return new self($caller->session()->user(), $idempotencyKey, $reference, null, null);
    }

    public function user(): UserReference
    {
        return $this->user;
    }

    public function idempotencyKey(): IdempotencyKey
    {
        return $this->idempotencyKey;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function number(): ?string
    {
        return $this->number;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    /**
     * `API-063` ‡: a repeated key whose content differs is refused, so the
     * fingerprint covers everything the request asks for.
     *
     * The user is included even though the key is the caller's: two callers
     * cannot share a key, and including it costs nothing to be certain.
     */
    public function contentFingerprint(): string
    {
        return hash('sha256', implode("\0", [
            $this->user->toString(),
            $this->reference ?? '',
            $this->number ?? '',
            $this->label ?? '',
        ]));
    }

    /**
     * `SEC-066` ‡ / `BE-181` ‡: the party is the user the **session** is bound
     * to, read from platform state rather than from anything the caller sent.
     *
     * That is the whole of the access rule for this resource. A contact belongs
     * to exactly one user, the caller may act only on their own set, and there is
     * no path by which a caller names whose set they mean — the route carries a
     * contact reference and the service resolves it **within** the set this
     * reference selects.
     *
     * @return list<ActorReference>
     */
    public function partyReferences(): array
    {
        return [ActorReference::fromString($this->user->toString())];
    }

    /**
     * `SEC-058` ‡ / `API-105` ‡: nominating, amending or removing an emergency
     * contact changes what the platform holds, not what the caller may do.
     *
     * A nominated contact is **not** a party to anything — `UC-OQ-006` records
     * that they are not even necessarily told — so no entitlement moves to them
     * either.
     */
    public function entitlementSubject(): ?ActorReference
    {
        return null;
    }
}
