<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Event;

/**
 * An aggregate that records domain events for the application service to collect.
 *
 * The contract behind {@see RecordsDomainEvents}. It exists so that the
 * collector can be typed rather than reflected upon: an aggregate either
 * declares that it records events or it does not, and a typo in a trait name
 * cannot make events silently go uncollected.
 *
 * `BE-022`: an aggregate records domain events rather than dispatching them.
 */
interface EventRecordingAggregate
{
    /**
     * Hands over what has been recorded and clears it.
     *
     * @return list<DomainEvent>
     */
    public function releaseRecordedEvents(): array;

    /**
     * @return list<DomainEvent>
     */
    public function recordedEvents(): array;
}
