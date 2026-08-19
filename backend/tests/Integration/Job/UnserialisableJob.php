<?php

declare(strict_types=1);

namespace Tests\Integration\Job;

use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\Work\JobFamily;
use Cmp\Infrastructure\Job\PlatformJob;

/**
 * A job whose payload cannot be written to the store, used to prove `BE-141`
 * fails loudly rather than quietly.
 */
final class UnserialisableJob extends PlatformJob
{
    public function family(): JobFamily
    {
        return JobFamily::Maintenance;
    }

    public function operationName(): string
    {
        return 'test.unserialisable';
    }

    protected function payload(): array
    {
        // Invalid UTF-8 cannot be JSON-encoded, so it cannot reach the job store
        // either — which is exactly what BE-141 forbids a job depending on.
        return ['thing' => "\xB1\x31"];
    }

    protected function perform(): Result
    {
        return Result::succeeded();
    }
}
