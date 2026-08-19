<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Event;

use Cmp\Domain\Shared\Event\DomainEvent;
use Cmp\Domain\Shared\Event\EventRecordingAggregate;

/**
 * Collects the events an aggregate recorded during a unit of work, so that they
 * can be dispatched **after** it commits.
 *
 * `BE-022` has the aggregate record; this is where the application service
 * collects (`BADR-06`). Holding them here rather than dispatching as they arrive
 * is what makes `BE-057` ‡ possible.
 */
final class DomainEventRecorder
{
    /** @var list<DomainEvent> */
    private array $pending = [];

    public function record(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->pending[] = $event;
        }
    }

    /**
     * Collects everything an aggregate has recorded and clears it there.
     */
    public function collectFrom(EventRecordingAggregate $aggregate): void
    {
        $this->record(...$aggregate->releaseRecordedEvents());
    }

    /**
     * @return list<DomainEvent>
     */
    public function release(): array
    {
        $released = $this->pending;
        $this->pending = [];

        return $released;
    }

    /**
     * `BE-053` ‡: a failed operation leaves no partial effect. An event recorded
     * inside a rolled-back transaction describes something that did not happen,
     * and must never reach a listener.
     */
    public function discard(): void
    {
        $this->pending = [];
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }
}
