<?php

declare(strict_types=1);

namespace Cmp\Interface\Console;

use Cmp\Application\Shared\Schema\SchemaVerification;
use Cmp\Application\Shared\Schema\VerifySchema;
use Illuminate\Console\Command;

/**
 * Verifies the schema against CMP-DOC-11.
 *
 * An adapter (`BE-005`): it holds no rule of its own, decides no sequence, and
 * reaches the components that do only through the application service
 * (`BE-013`). The same checks run automatically around every migration; this
 * command exists so they can be run on demand, in every environment
 * (`TC-040` ‡), and not only inside a pipeline.
 */
final class VerifySchemaCommand extends Command
{
    protected $signature = 'schema:verify {--skip-database : Run only the checks that read files}';

    protected $description = 'Verify the schema against CMP-DOC-11: destructive-migration approval, CHECK enforcement, and the naming and domain conventions.';

    public function handle(VerifySchema $verify): int
    {
        $verifications = [$verify->verifyMigrationApprovals()];

        if ($this->option('skip-database') !== true) {
            $verifications[] = $verify->verifyCheckEnforcement();
            $verifications[] = $verify->verifyConventions();
        }

        $failed = false;

        foreach ($verifications as $verification) {
            if ($verification->satisfied()) {
                $this->components->info($verification->assertion());

                continue;
            }

            $this->report($verification);
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function report(SchemaVerification $verification): void
    {
        $this->components->error($verification->assertion());

        foreach ($verification->violations() as $violation) {
            $this->line('  - '.$violation->describe());
        }
    }
}
