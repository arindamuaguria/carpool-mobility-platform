<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Domain\Shared\Event\DomainEvent;
use Cmp\Domain\Shared\Time\Instant;

/**
 * A domain event used by the dispatch tests.
 *
 * A **test double**, not a platform event. The platform's events belong to the
 * nine aggregates of `BE-017` and arrive with their features. It is `final` with
 * `readonly` properties because `BE-039` requires a domain event to be
 * immutable, and DomainEventImmutabilityTest asserts that of every
 * implementation — including this one.
 */
final class ThingHappened implements DomainEvent
{
    public function __construct(
        private readonly Instant $occurredAt,
        private readonly string $subject,
    ) {}

    public function occurredAt(): Instant
    {
        return $this->occurredAt;
    }

    public function eventName(): string
    {
        return 'test.thing_happened';
    }

    /**
     * `BE-039`: sufficient context for a listener to act without re-reading the
     * aggregate.
     */
    public function subject(): string
    {
        return $this->subject;
    }
}
