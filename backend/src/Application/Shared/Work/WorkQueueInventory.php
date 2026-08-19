<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Work;

/**
 * What is waiting, per family.
 *
 * `DB-148` / `BE-204`: queue depth and oldest-item age are derivable **per
 * family** from the job store. Without that, `BE-132` ‡ — safety work never
 * queuing behind another family — is a claim nobody can check, and `BE-133`'s
 * independent draining is something nobody can watch happen.
 *
 * `BE-138` ‡ makes the failed count for the safety family an operational
 * condition rather than a statistic.
 */
interface WorkQueueInventory
{
    public function depthOf(JobFamily $family): int;

    /**
     * How long the oldest waiting item has been available, in seconds, or null
     * where the family has nothing waiting.
     */
    public function oldestWaitingSecondsOf(JobFamily $family): ?int;

    /**
     * `DB-147` / `BE-137`: exhausted jobs are visible to operations, not
     * discarded.
     */
    public function failedCountOf(JobFamily $family): int;
}
