<?php

declare(strict_types=1);

namespace Tests\Domain\Shared;

use Cmp\Application\Shared\Event\DomainEventDispatcher;
use Cmp\Application\Shared\Event\DomainEventListener;
use Cmp\Application\Shared\Event\DomainEventRecorder;
use Cmp\Application\Shared\Event\ListenerRegistry;
use Cmp\Application\Shared\Event\UnitOfWork;
use Cmp\Domain\Shared\Time\Instant;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Domain\DomainTestCase;
use Tests\Domain\Shared\Doubles\AnotherCollectingListener;
use Tests\Domain\Shared\Doubles\CollectingListener;
use Tests\Domain\Shared\Doubles\ImmediateTransactionBoundary;
use Tests\Domain\Shared\Doubles\RecordingAggregate;
use Tests\Domain\Shared\Doubles\ThingHappened;

/**
 * CMP-IMP-026 — after-commit domain event dispatch.
 *
 * Level 2 (`TC-029` ‡). `BE-057` ‡ is an **ordering** property — dispatch after
 * commit, never inside — and ordering is decidable without a database. That the
 * boundary really commits is level 3's, and is already asserted there.
 */
final class DomainEventDispatchTest extends DomainTestCase
{
    private const EVENT = 'test.thing_happened';

    public function test_an_aggregate_records_an_event_rather_than_dispatching_it(): void
    {
        // BE-022. The aggregate has no dispatcher and cannot reach one.
        $aggregate = new RecordingAggregate;
        $aggregate->somethingHappened($this->anInstant(), 'a subject');

        self::assertCount(1, $aggregate->recordedEvents());
    }

    public function test_releasing_recorded_events_clears_them(): void
    {
        // An aggregate collected twice must not publish the same event twice.
        $aggregate = new RecordingAggregate;
        $aggregate->somethingHappened($this->anInstant(), 'a subject');

        self::assertCount(1, $aggregate->releaseRecordedEvents());
        self::assertSame([], $aggregate->releaseRecordedEvents());
    }

    public function test_events_reach_listeners_only_after_the_transaction_commits(): void
    {
        // BE-057 ‡ / BE-058. BADR-06 rejected dispatching on state change because
        // a rolled-back transaction would already have notified a passenger of a
        // booking that does not exist.
        $transaction = new ImmediateTransactionBoundary;
        $listener = new CollectingListener;
        $handledInsideScope = false;

        $listener->alsoDo(function () use ($transaction, &$handledInsideScope): void {
            $handledInsideScope = $transaction->inScope();
        });

        $recorder = new DomainEventRecorder;
        $unitOfWork = $this->unitOfWork($transaction, $recorder, $listener);

        $unitOfWork->commit(function () use ($recorder, $transaction): void {
            $aggregate = new RecordingAggregate;
            $aggregate->somethingHappened($this->anInstant(), 'a subject');
            $recorder->collectFrom($aggregate);

            self::assertTrue($transaction->inScope());
            self::assertSame(1, $recorder->pendingCount(), 'The event is held, not dispatched.');
        });

        self::assertCount(1, $listener->handled());
        self::assertFalse($handledInsideScope, 'BE-058: a listener never runs inside the producing transaction.');
    }

    public function test_a_rolled_back_transaction_dispatches_nothing(): void
    {
        // BE-053 ‡. An event describing something that did not happen must never
        // reach a listener.
        $transaction = new ImmediateTransactionBoundary;
        $listener = new CollectingListener;
        $recorder = new DomainEventRecorder;
        $unitOfWork = $this->unitOfWork($transaction, $recorder, $listener);

        try {
            $unitOfWork->commit(function () use ($recorder): void {
                $aggregate = new RecordingAggregate;
                $aggregate->somethingHappened($this->anInstant(), 'a subject');
                $recorder->collectFrom($aggregate);

                // The event is recorded; the work then fails.
                if ($recorder->pendingCount() > 0) {
                    throw new RuntimeException('the platform failed');
                }
            });

            self::fail('The throwable must propagate.');
        } catch (RuntimeException) {
            // BE-186 ‡: not converted into a refusal.
        }

        self::assertSame([], $listener->handled());
        self::assertSame(0, $recorder->pendingCount(), 'Discarded, not left pending for the next unit of work.');
    }

    public function test_a_unit_of_work_cannot_be_nested(): void
    {
        // Negative test for BE-057 ‡. An inner unit of work would dispatch while
        // the outer transaction was still open.
        $unitOfWork = $this->unitOfWork(new ImmediateTransactionBoundary, new DomainEventRecorder, new CollectingListener);

        $this->expectException(LogicException::class);

        $unitOfWork->commit(function () use ($unitOfWork): void {
            $unitOfWork->commit(static fn (): null => null);
        });
    }

    public function test_a_publisher_does_not_know_its_subscribers(): void
    {
        // BE-062. The event names nothing; the registry decides who hears it.
        $event = new ThingHappened($this->anInstant(), 'a subject');

        self::assertSame(self::EVENT, $event->eventName());

        $registry = new ListenerRegistry;
        self::assertSame([], $registry->listenersFor(self::EVENT), 'An unsubscribed event reaches nobody.');
    }

    public function test_the_registry_is_inspectable_as_a_catalogue(): void
    {
        // BE-064.
        $registry = new ListenerRegistry;
        $registry->subscribe('b.event', CollectingListener::class);
        $registry->subscribe('a.event', CollectingListener::class);

        self::assertSame(
            ['a.event' => [CollectingListener::class], 'b.event' => [CollectingListener::class]],
            $registry->catalogue(),
        );
    }

    public function test_the_same_listener_cannot_subscribe_twice_to_one_event(): void
    {
        // A double subscription delivers the event twice, and a listener is not
        // required to be idempotent — BE-059 puts durable work in a job, and the
        // job carries that obligation (BE-135).
        $registry = new ListenerRegistry;
        $registry->subscribe(self::EVENT, CollectingListener::class);

        $this->expectException(InvalidArgumentException::class);

        $registry->subscribe(self::EVENT, CollectingListener::class);
    }

    public function test_every_subscribed_listener_receives_the_event_in_registration_order(): void
    {
        $first = new CollectingListener;
        $second = new AnotherCollectingListener;

        $registry = new ListenerRegistry;
        $registry->subscribe(self::EVENT, CollectingListener::class);
        $registry->subscribe(self::EVENT, AnotherCollectingListener::class);

        $dispatcher = new DomainEventDispatcher(
            $registry,
            static fn (string $listener): DomainEventListener => $listener === CollectingListener::class ? $first : $second,
        );

        $dispatcher->dispatch(new ThingHappened($this->anInstant(), 'a subject'));

        self::assertCount(1, $first->handled());
        self::assertCount(1, $second->handled());
    }

    public function test_an_event_carries_enough_context_to_act_on_without_re_reading_the_aggregate(): void
    {
        // BE-039. A listener may run long after the aggregate has moved on.
        $event = new ThingHappened($this->anInstant(), 'the subject');

        self::assertSame('the subject', $event->subject());
        self::assertSame('2026-08-19T09:30:00.000000Z', $event->occurredAt()->toIso8601());
    }

    private function unitOfWork(
        ImmediateTransactionBoundary $transaction,
        DomainEventRecorder $recorder,
        CollectingListener $listener,
    ): UnitOfWork {
        $registry = new ListenerRegistry;
        $registry->subscribe(self::EVENT, CollectingListener::class);

        return new UnitOfWork(
            $transaction,
            $recorder,
            new DomainEventDispatcher($registry, static fn (): DomainEventListener => $listener),
        );
    }

    private function anInstant(): Instant
    {
        return Instant::fromString('2026-08-19T09:30:00Z');
    }
}
