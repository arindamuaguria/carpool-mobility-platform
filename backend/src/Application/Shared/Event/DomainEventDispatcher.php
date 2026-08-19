<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Event;

use Cmp\Domain\Shared\Event\DomainEvent;

/**
 * Delivers a domain event to the listeners the registry names for it.
 *
 * `BE-062`: the publisher does not know its subscribers, so this is the only
 * thing that does, and it learns it from {@see ListenerRegistry} rather than
 * from the event.
 *
 * **This class does not decide *when* to dispatch.** `BE-057` ‡ — only after the
 * producing transaction commits — is {@see UnitOfWork}'s, which is the only
 * thing that knows a transaction committed.
 *
 * A listener that throws stops the ones after it. That is deliberate and is not
 * softened: swallowing it would report an operation as fully handled when part
 * of the handling did not happen, and `BE-060` is the documented recovery —
 * projections and evidential records are reconciled from authoritative state
 * where a dispatch is lost.
 */
final class DomainEventDispatcher
{
    /**
     * @param  callable(class-string<DomainEventListener>): DomainEventListener  $resolve
     */
    public function __construct(
        private readonly ListenerRegistry $registry,
        private readonly mixed $resolve,
    ) {}

    public function dispatch(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            foreach ($this->registry->listenersFor($event->eventName()) as $listener) {
                ($this->resolver())($listener)->handle($event);
            }
        }
    }

    /**
     * @return callable(class-string<DomainEventListener>): DomainEventListener
     */
    private function resolver(): callable
    {
        /** @var callable(class-string<DomainEventListener>): DomainEventListener $resolve */
        $resolve = $this->resolve;

        return $resolve;
    }
}
