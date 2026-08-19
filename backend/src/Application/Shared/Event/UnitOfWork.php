<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Event;

use Cmp\Application\Shared\Transaction\TransactionBoundary;
use LogicException;
use Throwable;

/**
 * One transaction, and the events it produced.
 *
 * `BE-057` ‡ — **domain events are dispatched only after the producing
 * transaction commits** — is made structural here rather than left to be
 * remembered: the dispatch happens after `TransactionBoundary::transactional`
 * has returned, and that call does not return until the transaction has
 * committed. There is no ordering a caller can get wrong, because the caller
 * does not choose the ordering.
 *
 * `BE-058`: a listener therefore never executes within the producing
 * transaction. `BADR-06` rejected dispatching on state change for exactly this
 * reason — a rolled-back transaction would already have notified a passenger of
 * a booking that does not exist.
 *
 * `BE-053` ‡: on a throwable the recorded events are discarded and the throwable
 * propagates. An event describing something that did not happen must never reach
 * a listener.
 *
 * **Nesting is refused.** An inner unit of work would return — and dispatch —
 * while the outer transaction was still open, which is precisely what
 * `BE-057` ‡ forbids. `BE-054` asks for the narrowest scope that preserves the
 * required atomicity, not for nested scopes.
 *
 * `BE-060`: a crash between commit and dispatch loses the event. `BADR-06`
 * records that consequence and its mitigation — projections and evidential
 * records are reconcilable from authoritative state — which is
 * `CMP-IMP-448` and is not implemented here.
 */
final class UnitOfWork
{
    private bool $open = false;

    public function __construct(
        private readonly TransactionBoundary $transaction,
        private readonly DomainEventRecorder $recorder,
        private readonly DomainEventDispatcher $dispatcher,
    ) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function commit(callable $work): mixed
    {
        if ($this->open) {
            throw new LogicException(
                'BE-057 ‡: a unit of work cannot be nested. The inner one would dispatch its '
                .'events while the outer transaction was still open.'
            );
        }

        $this->open = true;

        try {
            $outcome = $this->transaction->transactional($work);
        } catch (Throwable $throwable) {
            // BE-053 ‡: the transaction rolled back, so nothing it recorded
            // happened.
            $this->recorder->discard();

            throw $throwable;
        } finally {
            $this->open = false;
        }

        // Committed. Only now (BE-057 ‡).
        $this->dispatcher->dispatch(...$this->recorder->release());

        return $outcome;
    }
}
