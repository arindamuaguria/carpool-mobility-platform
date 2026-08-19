<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Domain\Shared\Event\EventRecordingAggregate;
use Cmp\Domain\Shared\Event\RecordsDomainEvents;
use Cmp\Domain\Shared\Time\Instant;

/**
 * An aggregate that records events, used by the dispatch tests.
 *
 * A **test double**. The nine aggregates of `BE-017` arrive with their features;
 * this stands in for the one property under test — `BE-022`, an aggregate
 * records rather than dispatches.
 */
final class RecordingAggregate implements EventRecordingAggregate
{
    use RecordsDomainEvents;

    public function somethingHappened(Instant $at, string $subject): void
    {
        // BE-022: recorded, not dispatched. Nothing here knows a dispatcher
        // exists, and nothing here can reach one.
        $this->recordThat(new ThingHappened($at, $subject));
    }
}
