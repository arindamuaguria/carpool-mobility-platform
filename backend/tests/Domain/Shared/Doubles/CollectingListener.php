<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Application\Shared\Event\DomainEventListener;
use Cmp\Domain\Shared\Event\DomainEvent;

/**
 * A listener that records what it was handed, used by the dispatch tests.
 */
final class CollectingListener implements DomainEventListener
{
    /** @var list<DomainEvent> */
    private array $handled = [];

    /** @var callable(DomainEvent): void|null */
    private $onHandle = null;

    /**
     * @param  callable(DomainEvent): void  $onHandle
     */
    public function alsoDo(callable $onHandle): void
    {
        $this->onHandle = $onHandle;
    }

    public function handle(DomainEvent $event): void
    {
        $this->handled[] = $event;

        if ($this->onHandle !== null) {
            ($this->onHandle)($event);
        }
    }

    /**
     * @return list<DomainEvent>
     */
    public function handled(): array
    {
        return $this->handled;
    }
}
