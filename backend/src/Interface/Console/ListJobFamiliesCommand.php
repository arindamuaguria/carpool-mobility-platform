<?php

declare(strict_types=1);

namespace Cmp\Interface\Console;

use Cmp\Application\Shared\Work\JobFamily;
use Cmp\Application\Shared\Work\WorkQueueInventory;
use Illuminate\Console\Command;

/**
 * Reports the seven job families, their queues and what is waiting on each.
 *
 * `DB-148` / `BE-204` require depth and oldest-item age to be derivable per
 * family; this is where operations reads them. `BE-133` — each family
 * independently drainable and pausable — is a property of the queue separation,
 * and this command is how you watch a family drain.
 *
 * An adapter (`BE-005`): it holds no rule and reaches the store only through the
 * application contract.
 */
final class ListJobFamiliesCommand extends Command
{
    protected $signature = 'work:families';

    protected $description = 'List the seven job families in priority order with queue depth, oldest waiting age and failed count.';

    public function handle(WorkQueueInventory $inventory): int
    {
        $rows = [];

        foreach (JobFamily::inPriorityOrder() as $family) {
            $oldest = $inventory->oldestWaitingSecondsOf($family);

            $rows[] = [
                $family->priority(),
                $family->queue(),
                $family->isSafety() ? 'yes' : '—',
                $inventory->depthOf($family),
                $oldest === null ? '—' : $oldest.'s',
                $inventory->failedCountOf($family),
            ];
        }

        $this->table(['Pri', 'Family / queue', 'Safety', 'Waiting', 'Oldest', 'Failed'], $rows);

        $this->line('');
        $this->components->info(sprintf(
            'BE-131 — %d families, and no undeclared eighth.',
            count(JobFamily::cases()),
        ));
        $this->line('  A worker taking every family in priority order:');
        $this->line('    php artisan queue:work --queue='.JobFamily::allQueuesInPriorityOrder());
        $this->line('  BE-133 — a family is drained or paused on its own by working or stopping its queue alone:');
        $this->line('    php artisan queue:work --queue='.JobFamily::Safety->queue().' --stop-when-empty');

        return self::SUCCESS;
    }
}
