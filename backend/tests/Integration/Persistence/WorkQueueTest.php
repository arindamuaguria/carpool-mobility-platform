<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Cmp\Application\Shared\Work\JobFamily;
use Cmp\Application\Shared\Work\WorkQueueInventory;
use Cmp\Infrastructure\Persistence\Work\DatabaseWorkQueueInventory;
use Illuminate\Support\Facades\Queue;
use Tests\Integration\IntegrationTestCase;

/**
 * CMP-IMP-027 — the job store and the per-family binding, against a real MySQL.
 *
 * Level 3 (`TC-030` ‡). The properties here are all properties of the deployed
 * schema: that each family lands on its own queue, that a family can be read
 * without scanning another (`DB-146` ‡), and that depth and age come from the
 * store itself (`DB-148`).
 */
final class WorkQueueTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearStore();
    }

    protected function tearDown(): void
    {
        $this->clearStore();

        parent::tearDown();
    }

    public function test_each_family_lands_on_its_own_queue(): void
    {
        // BE-133 / BE-132 ‡: a family that shared a queue with another could be
        // neither drained nor paused on its own, and safety would queue behind
        // whatever it shared with.
        foreach (JobFamily::cases() as $family) {
            $this->enqueueRaw($family, 1);
        }

        foreach (JobFamily::cases() as $family) {
            self::assertSame(1, $this->inventory()->depthOf($family), $family->value.' has its own queue.');
        }
    }

    public function test_depth_and_oldest_age_are_derivable_per_family(): void
    {
        // DB-148 / BE-204.
        $this->enqueueRaw(JobFamily::Notification, 3, availableSecondsAgo: 120);
        $this->enqueueRaw(JobFamily::Safety, 1, availableSecondsAgo: 5);

        self::assertSame(3, $this->inventory()->depthOf(JobFamily::Notification));
        self::assertSame(1, $this->inventory()->depthOf(JobFamily::Safety));
        self::assertSame(0, $this->inventory()->depthOf(JobFamily::Maintenance));

        $oldest = $this->inventory()->oldestWaitingSecondsOf(JobFamily::Notification);
        self::assertNotNull($oldest);
        self::assertGreaterThanOrEqual(120, $oldest);

        self::assertNull(
            $this->inventory()->oldestWaitingSecondsOf(JobFamily::Maintenance),
            'A family with nothing waiting has no oldest item.',
        );
    }

    public function test_a_family_is_read_without_scanning_another(): void
    {
        // DB-146 ‡. The index is what makes safety selectable without walking
        // another family's rows; the plan is the evidence.
        $plan = $this->readConnection()->select(
            'EXPLAIN SELECT COUNT(*) FROM '.DatabaseWorkQueueInventory::JOBS_TABLE.' WHERE queue = ?',
            [JobFamily::Safety->queue()],
        );

        self::assertNotEmpty($plan);
        self::assertSame(
            'mch_jobs_queue_available_at_index',
            $plan[0]->key,
            'DB-146 ‡: reading one family must not scan the others.',
        );
    }

    public function test_the_default_queue_is_a_declared_family_and_not_an_eighth(): void
    {
        // BE-131. A framework default of "default" would be an undeclared eighth
        // family that anything forgetting to name one would land on.
        $configured = config('queue.connections.database.queue');

        self::assertIsString($configured);
        self::assertNotNull(JobFamily::tryFrom($configured));
    }

    public function test_the_job_store_is_the_relational_database(): void
    {
        // BE-142: job storage is the relational database at launch, behind an
        // interface permitting substitution. DB-149: deferred work therefore
        // survives restart, because the store is a transactional table.
        //
        // queue.default is not asserted here: phpunit.xml forces the sync driver
        // so a test can run a job inline. What the platform runs on is asserted
        // at level 1 against the environment inventory, which is where BE-015
        // puts it.
        self::assertSame(DatabaseWorkQueueInventory::JOBS_TABLE, config('queue.connections.database.table'));
        self::assertSame(DatabaseWorkQueueInventory::FAILED_JOBS_TABLE, config('queue.failed.table'));
        self::assertInstanceOf(WorkQueueInventory::class, $this->inventory());
    }

    public function test_an_exhausted_job_is_visible_to_operations_rather_than_discarded(): void
    {
        // DB-147 / BE-137. The failed store exists and is readable per family;
        // BE-138 ‡ makes a failed safety job an operational condition, which is
        // only possible because the row is still there.
        $this->migrationConnection()->insert(
            'INSERT INTO '.DatabaseWorkQueueInventory::FAILED_JOBS_TABLE
            .' (uuid, connection, queue, payload, exception, failed_at) VALUES (?, ?, ?, ?, ?, ?)',
            ['test-uuid-1', 'database', JobFamily::Safety->queue(), '{}', 'a failure', '2026-08-19 09:30:00'],
        );

        self::assertSame(1, $this->inventory()->failedCountOf(JobFamily::Safety));
        self::assertSame(0, $this->inventory()->failedCountOf(JobFamily::Notification));
    }

    public function test_a_job_enqueued_through_the_framework_lands_on_the_named_family(): void
    {
        // The binding is real, not just configured: pushing to a family's queue
        // puts the row where the inventory looks for it.
        Queue::connection('database')->pushRaw('{"job":"test"}', JobFamily::Projection->queue());

        self::assertSame(1, $this->inventory()->depthOf(JobFamily::Projection));
        self::assertSame(0, $this->inventory()->depthOf(JobFamily::Safety));
    }

    private function enqueueRaw(JobFamily $family, int $count, int $availableSecondsAgo = 0): void
    {
        $now = time();

        for ($i = 0; $i < $count; $i++) {
            $this->applicationConnection()->insert(
                'INSERT INTO '.DatabaseWorkQueueInventory::JOBS_TABLE
                .' (queue, payload, attempts, available_at, created_at) VALUES (?, ?, ?, ?, ?)',
                [$family->queue(), '{"job":"test"}', 0, $now - $availableSecondsAgo, $now - $availableSecondsAgo],
            );
        }
    }

    private function clearStore(): void
    {
        $this->applicationConnection()->delete('DELETE FROM '.DatabaseWorkQueueInventory::JOBS_TABLE);
        $this->applicationConnection()->delete('DELETE FROM '.DatabaseWorkQueueInventory::FAILED_JOBS_TABLE);
    }

    private function inventory(): WorkQueueInventory
    {
        return $this->app->make(WorkQueueInventory::class);
    }
}
