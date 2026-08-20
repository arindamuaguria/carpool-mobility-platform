<?php

declare(strict_types=1);

namespace Cmp\Domain\User\Event;

use Cmp\Domain\Shared\Event\DomainEvent;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\UserReference;

/**
 * Control of the account's phone number was demonstrated (`FRD-FR-008`).
 *
 * The account moves from `UNVERIFIED` to `VERIFIED` — `BAD-RULE-006`'s only
 * change of standing, and the only one this aggregate offers.
 *
 * `BAD-RULE-006` is why the change is recorded by the platform and cannot be
 * asserted: the client demonstrates, the platform decides, and this event is the
 * platform's own account of having decided.
 */
final class PhoneNumberVerified implements DomainEvent
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
        return 'user.phone_number_verified';
    }

    public function user(): UserReference
    {
        return $this->user;
    }
}
