<?php

declare(strict_types=1);

namespace Tests\Integration\Job;

use Cmp\Application\Shared\Idempotency\IdempotentOperation;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\Work\JobFamily;
use Cmp\Infrastructure\Job\PlatformJob;
use Cmp\Infrastructure\Persistence\Idempotency\DatabaseIdempotencyRegistry;
use Illuminate\Support\Facades\Queue;
use Tests\Integration\IntegrationTestCase;

/**
 * CMP-IMP-028 — every job idempotent, outcome-recording and failure-visible.
 *
 * Level 3 (`TC-030` ‡). `BE-135` ‡ — safe to execute more than once — is a
 * property of the idempotency registry's unique constraint, which does not exist
 * without a database. Running the job twice against a real MySQL is the only
 * honest way to assert it.
 */
final class PlatformJobTest extends IntegrationTestCase
{
    private const OPERATION = 'test.job_operation';

    protected function setUp(): void
    {
        parent::setUp();

        TestJob::$runs = 0;
        TestJob::$prepared = 0;
        TestJob::$transactionOpenDuringPrepare = null;

        $this->clearRegistry();
    }

    protected function tearDown(): void
    {
        $this->clearRegistry();

        parent::tearDown();
    }

    public function test_a_job_runs_its_work_once(): void
    {
        $this->execute(new TestJob('actor-1', 'job-key-1'));

        self::assertSame(1, TestJob::$runs);
    }

    public function test_re_executing_a_job_produces_no_second_effect(): void
    {
        // BE-135 ‡. A queue redelivers after a worker dies mid-run, after a
        // timeout and after a deploy; a job that assumed one execution would
        // double-charge the first time a worker was killed.
        $this->execute(new TestJob('actor-1', 'job-key-2'));
        $this->execute(new TestJob('actor-1', 'job-key-2'));

        self::assertSame(1, TestJob::$runs, 'The second execution must replay, not repeat.');
    }

    public function test_a_job_records_its_outcome(): void
    {
        // BE-136 / DB-143, in the same transaction as the effect (BE-051 ‡).
        $this->execute(new TestJob('actor-1', 'job-key-3'));

        $rows = $this->applicationConnection()->select(
            'SELECT outcome, completed_at FROM '.DatabaseIdempotencyRegistry::TABLE
            .' WHERE operation = ? AND request_key = ?',
            [self::OPERATION, 'job-key-3'],
        );

        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]->completed_at);
        // MySQL normalises whitespace in a JSON column, so the recorded outcome
        // is compared as a value rather than as text.
        self::assertSame(['handled' => true], json_decode((string) $rows[0]->outcome, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_the_identity_the_job_acts_for_is_carried_explicitly(): void
    {
        // BE-140. A worker has no ambient session to read one from.
        $job = new TestJob('actor-7', 'job-key-4');

        self::assertSame('actor-7', $job->actorReference()->toString());
    }

    public function test_the_same_key_held_by_a_different_actor_is_a_different_claim(): void
    {
        // API-060: the key is scoped to the acting actor and the operation.
        $this->execute(new TestJob('actor-1', 'job-key-5'));
        $this->execute(new TestJob('actor-2', 'job-key-5'));

        self::assertSame(2, TestJob::$runs);
    }

    public function test_a_provider_call_would_happen_before_the_transaction_opens(): void
    {
        // BE-050 ‡ / BE-052: prepare() runs outside the transactional scope, so a
        // job needing a provider result obtains it without holding a row lock for
        // as long as a third party takes to answer.
        $this->execute(new TestJob('actor-1', 'job-key-6'));

        self::assertSame(1, TestJob::$prepared);
        self::assertFalse(
            TestJob::$transactionOpenDuringPrepare,
            'BE-050 ‡: prepare() must not run inside a transaction.',
        );
    }

    public function test_a_job_is_bound_to_its_family_queue(): void
    {
        // BE-131 / BE-132 ‡: there is no path by which a job lands on a queue
        // other than its family's.
        Queue::fake();

        dispatch(new TestJob('actor-1', 'job-key-7'));

        Queue::assertPushedOn(JobFamily::Reconciliation->queue(), TestJob::class);
    }

    public function test_a_payload_that_cannot_be_serialised_is_refused(): void
    {
        // BE-141: a job depends on no memory state of the process that enqueued
        // it, so a payload that cannot be written to the store is a defect, not
        // something to be worked around at run time.
        $job = new UnserialisableJob('actor-1', 'job-key-8');

        $this->expectException(\LogicException::class);

        $job->contentFingerprint();
    }

    private function execute(PlatformJob $job): void
    {
        $job->handle($this->app->make(IdempotentOperation::class));
    }

    private function clearRegistry(): void
    {
        $this->applicationConnection()->delete(
            'DELETE FROM '.DatabaseIdempotencyRegistry::TABLE.' WHERE operation = ?',
            [self::OPERATION],
        );
    }
}
