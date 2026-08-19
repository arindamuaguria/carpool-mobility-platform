<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Event;

use InvalidArgumentException;

/**
 * The one registry of event subscriptions.
 *
 * `BE-064`: event subscription is declared in **one** registry, inspectable as a
 * catalogue. Subscriptions scattered across providers cannot be reviewed, and
 * `BE-063` turns on being able to see that a listener may be attached to trip
 * completion without touching the trip lifecycle — which is only reassuring if
 * the attachment is visible.
 *
 * `BE-062`: the publisher does not know its subscribers. An aggregate records an
 * event naming nothing; the registry decides who hears it.
 *
 * Listeners are held as class names and resolved when the event is dispatched,
 * so that registering a subscription costs nothing and a listener with expensive
 * dependencies is not constructed for an event that never fires.
 */
final class ListenerRegistry
{
    /** @var array<string, list<class-string<DomainEventListener>>> */
    private array $subscriptions = [];

    /**
     * @param  class-string<DomainEventListener>  $listener
     */
    public function subscribe(string $eventName, string $listener): void
    {
        if (trim($eventName) === '') {
            throw new InvalidArgumentException('BE-064: a subscription names the event it listens for.');
        }

        $existing = $this->subscriptions[$eventName] ?? [];

        if (in_array($listener, $existing, true)) {
            // Registering the same listener twice would deliver the event twice,
            // and a listener is not required to be idempotent — BE-059 puts
            // durable work in a job, and the job is what carries that obligation
            // (BE-135).
            throw new InvalidArgumentException(sprintf(
                '%s is already subscribed to %s.',
                $listener,
                $eventName,
            ));
        }

        $existing[] = $listener;
        $this->subscriptions[$eventName] = $existing;
    }

    /**
     * @return list<class-string<DomainEventListener>>
     */
    public function listenersFor(string $eventName): array
    {
        return $this->subscriptions[$eventName] ?? [];
    }

    /**
     * The catalogue `BE-064` requires: every event the platform publishes a
     * subscription for, and who hears it.
     *
     * @return array<string, list<class-string<DomainEventListener>>>
     */
    public function catalogue(): array
    {
        $catalogue = $this->subscriptions;
        ksort($catalogue);

        return $catalogue;
    }
}
