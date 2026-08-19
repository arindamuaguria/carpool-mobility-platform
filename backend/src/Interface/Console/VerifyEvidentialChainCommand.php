<?php

declare(strict_types=1);

namespace Cmp\Interface\Console;

use Cmp\Application\Shared\Evidence\VerifiesEvidentialChain;
use Illuminate\Console\Command;

/**
 * Runs a verification pass over the evidential chain.
 *
 * `BE-115` / `DB-115` / `SEC-111`. An adapter (`BE-005`): it holds no rule and
 * reaches the verifier through the application contract alone.
 *
 * `SEC-113` and `BE-146` place verification in scheduled reconciliation.
 * **It is not scheduled**, and `ScheduledWork` records why: `BE-148` puts the
 * frequency in policy configuration, and CMP-DOC-09 §13.2 records scheduled work
 * frequency as `[TBD – Technical Decision Required]`. The pass exists and runs on
 * demand; the cadence is a decision nobody has taken.
 *
 * `SEC-112` ‡: this reports and never repairs. A non-zero exit is a **finding**,
 * for a person to act on.
 */
final class VerifyEvidentialChainCommand extends Command
{
    protected $signature = 'evidence:verify-chain {--from= : Verify from this record onward, where an earlier point is independently trusted}';

    protected $description = 'Re-derive the evidential chain and report the first divergence (BE-115, SEC-111).';

    public function handle(VerifiesEvidentialChain $verifier): int
    {
        $from = $this->option('from');
        $fromRecordId = is_string($from) && $from !== '' ? (int) $from : null;

        $verification = $verifier->verify($fromRecordId);

        if ($verification->isIntact()) {
            $this->components->info($verification->describe());

            return self::SUCCESS;
        }

        // SEC-112 ‡: a break is a finding, not a fault to correct. Nothing here
        // attempts to fix it, and the exit code is how it reaches whoever is
        // watching.
        $this->components->error($verification->describe());

        return self::FAILURE;
    }
}
