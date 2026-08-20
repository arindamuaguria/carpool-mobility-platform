<?php

declare(strict_types=1);

namespace Cmp\Domain\User\Event;

use Cmp\Domain\Shared\Event\DomainEvent;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\UserReference;

/**
 * An account came into existence (`FRD-FR-001`, `FRD-FR-006`).
 *
 * `BE-022`: an aggregate **records** an event rather than dispatching one, and
 * `BE-058` dispatches after the transaction commits — so nothing a listener does
 * can be part of the registration, and nothing it fails at can undo one.
 *
 * `BE-039` makes an event immutable, which `DomainEventRulesTest` asserts of
 * every implementation: final, readonly, no setter.
 *
 * ## It carries a reference and not a number
 *
 * A domain event reaches listeners, jobs and — through the evidential writer — a
 * record that outlives the account. `BE-201` ‡ keeps a contact detail out of a
 * log, and a phone number is one. The reference identifies the account without
 * disclosing anything about the person, which is what `DB-024` ‡ made it for.
 */
final class UserRegistered implements DomainEvent
{
    public function __construct(
        private readonly UserReference $user,
        private readonly Instant $occurredAt,
    ) {}

    public function occurredAt(): Instant
    {
        return $this->occurredAt;
    }

    public function eventName(): string
    {
        return 'user.registered';
    }

    public function user(): UserReference
    {
        return $this->user;
    }
}
