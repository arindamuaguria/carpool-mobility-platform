<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Work;

use Cmp\Application\Shared\Work\JobFamily;
use Cmp\Application\Shared\Work\WorkQueueInventory;
use Cmp\Domain\Shared\Time\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Reads depth and age per family from `mch_jobs`, and the failed count from
 * `mch_failed_jobs`.
 *
 * `DB-148`: derivable **from the job store** — not from a counter maintained
 * alongside it, which could drift from the thing it claims to describe.
 * `DB-146` ‡: the `(queue, available_at)` index means reading one family never
 * scans another's rows.
 */
final class DatabaseWorkQueueInventory implements WorkQueueInventory
{
    public const JOBS_TABLE = 'mch_jobs';

    public const FAILED_JOBS_TABLE = 'mch_failed_jobs';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
    ) {}

    public function depthOf(JobFamily $family): int
    {
        /** @var list<object{total: int|string}> $rows */
        $rows = $this->connection->select(
            'SELECT COUNT(*) AS total FROM '.self::JOBS_TABLE.' WHERE queue = ?',
            [$family->queue()],
        );

        return (int) $rows[0]->total;
    }

    public function oldestWaitingSecondsOf(JobFamily $family): ?int
    {
        /** @var list<object{oldest: int|string|null}> $rows */
        $rows = $this->connection->select(
            'SELECT MIN(available_at) AS oldest FROM '.self::JOBS_TABLE.' WHERE queue = ?',
            [$family->queue()],
        );

        $oldest = $rows[0]->oldest ?? null;

        if ($oldest === null) {
            return null;
        }

        // Age against the platform's clock (BE-036), not the database's — one
        // source of the current instant, so an inventory reading cannot disagree
        // with a job's own idea of when it became available.
        $now = $this->clock->now()->toDateTime()->getTimestamp();

        return max(0, $now - (int) $oldest);
    }

    public function failedCountOf(JobFamily $family): int
    {
        /** @var list<object{total: int|string}> $rows */
        $rows = $this->connection->select(
            'SELECT COUNT(*) AS total FROM '.self::FAILED_JOBS_TABLE.' WHERE queue = ?',
            [$family->queue()],
        );

        return (int) $rows[0]->total;
    }
}
