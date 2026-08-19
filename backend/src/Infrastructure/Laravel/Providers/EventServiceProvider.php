<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Event\DomainEventDispatcher;
use Cmp\Application\Shared\Event\DomainEventListener;
use Cmp\Application\Shared\Event\DomainEventRecorder;
use Cmp\Application\Shared\Event\ListenerRegistry;
use Cmp\Application\Shared\Event\UnitOfWork;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Infrastructure\Time\SystemClock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The composition root for domain events and for the clock.
 *
 * `BE-064` requires event subscription to be declared in **one** registry,
 * inspectable as a catalogue. {@see subscriptions()} is that declaration: a
 * listener is registered there or it is not registered at all, and the
 * architecture suite asserts that nothing else calls `subscribe`.
 *
 * The framework's own event system is deliberately not used. Laravel's
 * dispatcher fires immediately, and `BE-057` ‡ requires dispatch only after the
 * producing transaction commits — a property {@see UnitOfWork} owns, and one
 * that a second dispatcher in the same application would quietly offer a way
 * around.
 */
final class EventServiceProvider extends ServiceProvider
{
    /**
     * The event subscription catalogue (`BE-064`).
     *
     * Empty. No aggregate publishes an event yet: the nine aggregates of
     * `BE-017` arrive with their features, and the two listeners the layer
     * diagram names — projection maintenance (`BADR-10`) and the evidential
     * writer (`BADR-09`) — are `CMP-IMP-439` and the projection work.
     *
     * `BE-063`: trip completion will publish an event to which rating and reward
     * listeners may later attach **without modification to the trip lifecycle**.
     * Those three areas carry zero functional requirements (CMP-DOC-04 §9.2) and
     * must not be built; the attachment point is what is being preserved, not the
     * feature.
     *
     * @return array<string, list<class-string<DomainEventListener>>>
     */
    public static function subscriptions(): array
    {
        return [];
    }

    public function register(): void
    {
        $this->app->singleton(Clock::class, static fn (): SystemClock => new SystemClock);

        $this->app->singleton(ListenerRegistry::class, static function (): ListenerRegistry {
            $registry = new ListenerRegistry;

            foreach (self::subscriptions() as $eventName => $listeners) {
                foreach ($listeners as $listener) {
                    $registry->subscribe($eventName, $listener);
                }
            }

            return $registry;
        });

        // One recorder per request or per worker run: a unit of work collects
        // into it and releases from it, and nothing outside a unit of work
        // should hold events that were never committed.
        $this->app->singleton(DomainEventRecorder::class, static fn (): DomainEventRecorder => new DomainEventRecorder);

        $this->app->singleton(
            DomainEventDispatcher::class,
            static fn (Application $app): DomainEventDispatcher => new DomainEventDispatcher(
                $app->make(ListenerRegistry::class),
                /** @param class-string<DomainEventListener> $listener */
                static function (string $listener) use ($app): DomainEventListener {
                    $resolved = $app->make($listener);

                    if (! $resolved instanceof DomainEventListener) {
                        throw new \RuntimeException($listener.' is registered as a listener but is not one.');
                    }

                    return $resolved;
                },
            ),
        );

        $this->app->bind(
            UnitOfWork::class,
            static fn (Application $app): UnitOfWork => new UnitOfWork(
                $app->make(TransactionBoundary::class),
                $app->make(DomainEventRecorder::class),
                $app->make(DomainEventDispatcher::class),
            ),
        );
    }
}
