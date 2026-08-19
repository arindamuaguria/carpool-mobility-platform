<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Application\Shared\Event\DomainEventListener;
use Cmp\Application\Shared\Event\ListenerRegistry;
use Cmp\Domain\Shared\Event\DomainEvent;

/**
 * A second listener, so that "every subscriber receives the event" can be
 * asserted with two distinct classes rather than one registered twice — which
 * {@see ListenerRegistry} refuses.
 */
final class AnotherCollectingListener implements DomainEventListener
{
    /** @var list<DomainEvent> */
    private array $handled = [];

    public function handle(DomainEvent $event): void
    {
        $this->handled[] = $event;
    }

    /**
     * @return list<DomainEvent>
     */
    public function handled(): array
    {
        return $this->handled;
    }
}
