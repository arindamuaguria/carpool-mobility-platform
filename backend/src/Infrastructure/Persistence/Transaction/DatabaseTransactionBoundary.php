<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Transaction;

use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Illuminate\Database\ConnectionInterface;

/**
 * The one place in the platform that opens a database transaction.
 *
 * `BE-047` ‡ vests transaction control in the application layer. This is the
 * infrastructure that carries it out; nothing else may call `beginTransaction`,
 * `commit` or `rollBack`, and `TC-037` rule 4 fails the build if anything does.
 *
 * `BE-053` ‡: a failed operation leaves no partial effect. The driver rolls back
 * on any throwable and rethrows, so a caller can never observe half of an
 * operation.
 *
 * No retry on deadlock is configured. A silent retry would re-run work whose
 * effects the caller has not been told about; where a retry is right, the
 * application service decides it.
 */
final class DatabaseTransactionBoundary implements TransactionBoundary
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function transactional(callable $work): mixed
    {
        return $this->connection->transaction(static fn (): mixed => $work());
    }
}
