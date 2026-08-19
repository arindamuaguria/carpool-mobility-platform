<?php

declare(strict_types=1);

namespace Tests\Integration\Job;

use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\Work\JobFamily;
use Cmp\Infrastructure\Job\PlatformJob;
use Illuminate\Support\Facades\DB;

/**
 * A job used by PlatformJobTest.
 *
 * A **test double**. The platform's jobs belong to the features that enqueue
 * them; this stands in for the contract every job inherits.
 */
final class TestJob extends PlatformJob
{
    public static int $runs = 0;

    public static int $prepared = 0;

    public static ?bool $transactionOpenDuringPrepare = null;

    public function family(): JobFamily
    {
        return JobFamily::Reconciliation;
    }

    public function operationName(): string
    {
        return 'test.job_operation';
    }

    protected function payload(): array
    {
        return ['thing' => 'a value'];
    }

    protected function prepare(): void
    {
        self::$prepared++;

        // BE-050 ‡: recorded so the test can assert this ran outside any
        // transactional scope.
        self::$transactionOpenDuringPrepare = DB::transactionLevel() > 0;
    }

    protected function perform(): Result
    {
        self::$runs++;

        return Result::success(['handled' => true]);
    }
}
