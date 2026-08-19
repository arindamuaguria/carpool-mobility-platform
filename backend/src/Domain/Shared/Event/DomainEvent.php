<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Event;

use Cmp\Domain\Shared\Time\Instant;

/**
 * Something that happened in the domain.
 *
 * `BE-039`: a domain event is **immutable** and carries sufficient context for a
 * listener to act **without re-reading the aggregate**. A listener runs after the
 * producing transaction has committed (`BE-057` ‡) and may run much later still
 * if it enqueued a job (`BE-059`); by then the aggregate may have moved on, so an
 * event that only names an identifier would make the listener read a state that
 * is no longer the one the event describes.
 *
 * `BE-062`: publishing an event does not require the publisher to know its
 * subscribers. Nothing on this interface names a listener.
 *
 * Immutability is asserted structurally in DomainEventImmutabilityTest: every
 * implementation is `final` and every property `readonly`.
 */
interface DomainEvent
{
    /**
     * When the thing this event describes happened — not when it is dispatched.
     */
    public function occurredAt(): Instant;

    /**
     * The event's stable name, used by the listener registry and by anything
     * recording a catalogue of what the platform publishes (`BE-064`).
     */
    public function eventName(): string;
}
