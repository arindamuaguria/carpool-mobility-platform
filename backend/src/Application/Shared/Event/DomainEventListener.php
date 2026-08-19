<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Event;

use Cmp\Domain\Shared\Event\DomainEvent;

/**
 * Something that reacts to a domain event, after the producing transaction has
 * committed.
 *
 * `BE-061`: **a listener contains no business rule.** A rule lives in exactly one
 * Domain component (`BE-010`) and is not reachable for override (`BE-012`); a
 * rule implemented in a listener would be a second copy of it, running outside
 * the transaction that enforced the first.
 *
 * `BE-058`: a listener never executes within the producing transaction.
 * `BE-059`: a listener performing durable work **enqueues a job** rather than
 * doing it inline — the job families are `BADR-07` and `CMP-IMP-027`.
 */
interface DomainEventListener
{
    public function handle(DomainEvent $event): void;
}
