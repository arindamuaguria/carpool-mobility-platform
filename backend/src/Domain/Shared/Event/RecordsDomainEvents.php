<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Event;

/**
 * An aggregate that records domain events.
 *
 * `BE-022`: an aggregate **records** domain events rather than dispatching them.
 * Dispatching from inside the aggregate would put the listener inside the
 * producing transaction, which `BE-058` forbids, and would let an effect outlive
 * a rolled-back transaction — `BADR-06` rejected that alternative explicitly,
 * because it would have notified a passenger of a booking that does not exist.
 *
 * `BE-020`: cross-aggregate consistency is achieved by these events, not by one
 * aggregate reaching into another (`BE-019`).
 */
trait RecordsDomainEvents
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * Hands over what has been recorded and clears it, so that an aggregate
     * collected twice does not publish the same event twice.
     *
     * @return list<DomainEvent>
     */
    public function releaseRecordedEvents(): array
    {
        $released = $this->recordedEvents;
        $this->recordedEvents = [];

        return $released;
    }

    /**
     * @return list<DomainEvent>
     */
    public function recordedEvents(): array
    {
        return $this->recordedEvents;
    }
}
