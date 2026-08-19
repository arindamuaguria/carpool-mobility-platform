<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Throwable;

/**
 * A transaction boundary that records what happened inside its scope.
 *
 * `TC-029` ‡ forbids a database at level 2, so this stands in for one — but it
 * keeps the property under test: work runs **inside** a scope, and a throwable
 * discards everything recorded within it (`BE-053` ‡).
 *
 * The real boundary, and the real rollback, are asserted at level 3 against
 * MySQL (`TC-030` ‡).
 */
final class ImmediateTransactionBoundary implements TransactionBoundary
{
    private int $openedScopes = 0;

    private int $depth = 0;

    /** @var list<string> */
    private array $eventsWithin = [];

    /** @var list<callable(): void> */
    private array $rollbackHandlers = [];

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function transactional(callable $work): mixed
    {
        $this->openedScopes++;
        $this->depth++;

        try {
            return $work();
        } catch (Throwable $throwable) {
            foreach ($this->rollbackHandlers as $handler) {
                $handler();
            }

            throw $throwable;
        } finally {
            $this->depth--;
        }
    }

    public function inScope(): bool
    {
        return $this->depth > 0;
    }

    public function record(string $event): void
    {
        if ($this->inScope()) {
            $this->eventsWithin[] = $event;
        }
    }

    /**
     * @param  callable(): void  $handler
     */
    public function onRollback(callable $handler): void
    {
        $this->rollbackHandlers[] = $handler;
    }

    public function openedScopes(): int
    {
        return $this->openedScopes;
    }

    /**
     * @return list<string>
     */
    public function eventsWithin(): array
    {
        return $this->eventsWithin;
    }
}
