<?php

declare(strict_types=1);

namespace Cmp\Interface\Console;

use Cmp\Application\Safety\RetrySafetyRouting;
use Illuminate\Console\Command;

/**
 * `safety:route-pending` — `FRD-FR-190` ‡'s retry, with a hand on it.
 *
 * *"The system shall retain and retry an incident that cannot immediately reach
 * the operator queue."* Retention is the row; this is the retry. It finds every
 * incident with no `routed_at` and dispatches it again — by query, because a
 * process that died between committing and dispatching remembers nothing it was
 * about to do.
 *
 * ## Why a command and not a schedule
 *
 * `BE-148` makes scheduled-work frequency **policy configuration**, and none is
 * set: `CMP-IMP-029` declared the scheduling point and `ScheduledWork` declares
 * nothing in it. Putting *"every five minutes"* here would be inventing an
 * operational figure in the one place nobody would look for it, and
 * `PolicyServiceProvider` records the same reasoning for the eleven values
 * CMP-DOC-09 §13.2 lists — *"their existence is architecture; their values are
 * not invented here."*
 *
 * So the retry exists, is invocable, and is exercised by the test suite. **When
 * it runs is a deployment decision** CMP-DOC-19 records, and until one is taken
 * an operator runs it.
 *
 * `BE-005`: an adapter. It holds no rule and decides nothing.
 */
final class RoutePendingSafetyIncidentsCommand extends Command
{
    protected $signature = 'safety:route-pending';

    protected $description = 'Re-dispatch every safety incident that has not reached the operator queue (FRD-FR-190 ‡).';

    public function handle(RetrySafetyRouting $retry): int
    {
        $outcome = $retry->execute();

        $this->line(json_encode([
            'command' => 'safety:route-pending',
            'attempted' => $outcome['attempted'],
            'dispatched' => $outcome['dispatched'],
            'more' => $outcome['more'],
        ], JSON_THROW_ON_ERROR));

        // FRD-FR-188 ‡: an incident the queue would not take is retained, not
        // lost, and the next pass finds it. That is not a failure of this
        // command, so it does not report one — an operator watching an exit code
        // would otherwise be told the platform had dropped something.
        return self::SUCCESS;
    }
}
