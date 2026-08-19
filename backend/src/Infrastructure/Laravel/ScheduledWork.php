<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel;

use Illuminate\Console\Scheduling\Schedule;

/**
 * The one place scheduled work is declared.
 *
 * `OPS-039` ‡ / `ARCH-140` / `BADR-07`: **exactly one scheduler instance is
 * active**, to prevent duplicate triggering. CMP-DOC-19 §"Deployment units"
 * names the scheduler as one of five independently deployable units, and that
 * exactly one of it runs is a deployment property this code cannot enforce — so
 * every entry declared here additionally guards itself with
 * `withoutOverlapping()`, which is a second layer and not the primary
 * mechanism.
 *
 * `BE-148`: scheduled work frequency is **configuration**, not code. `BADR-12`
 * puts configuration in the typed, versioned policy store, which is
 * `CMP-IMP-031` and does not exist yet. No frequency is written here in the
 * meantime, because a frequency in code is exactly what `BE-148` forbids and
 * would have to be found and removed later.
 *
 * ---
 *
 * **Nothing is scheduled.** `CMP-IMP-029` names four scheduled entries, and each
 * is blocked:
 *
 * | Entry | Family | Why it is not here |
 * |---|---|---|
 * | Recurring ride generation (`BE-143`, `BE-144` ‡) | `scheduled-generation` | **Withheld.** Recurring commute carries **zero functional requirements** (CMP-DOC-04 §9.2). `BE-144` ‡ has nothing to be idempotent about, and `ADM-187`/`ADM-191` forbid stubbing a withheld area. |
 * | Pending-payment resolution (`BE-145`) | `reconciliation` | The `Payment` aggregate does not exist (FEAT-016), and `FRD-FR-131` cannot be realised without it. |
 * | Evidential chain verification (`BE-146`) | `reconciliation` | `ev_evidential_records` does not exist. `CMP-IMP-448` schedules it, once `CMP-IMP-438` and `CMP-IMP-439` have built the log. |
 * | Retention enforcement (`BE-147`) | `maintenance` | **Blocked on a business decision.** `BAD-DEC-021` is open and 8 of 9 retention periods are unset (CMP-DOC-11 §13.2). Enforcing retention against a guessed period would delete evidence a party is entitled to. |
 *
 * An entry is added here on the commit that makes its work exist — never before,
 * and never as a placeholder that runs nothing.
 */
final class ScheduledWork
{
    /**
     * Declares the platform's scheduled work.
     *
     * @param  Schedule  $schedule  the framework's scheduler, reached only here
     */
    public static function declare(Schedule $schedule): void
    {
        // Intentionally empty. See the class docblock: every entry CMP-IMP-029
        // names is withheld, blocked on a business decision, or waiting on work
        // that has not been built. ScheduledWorkRulesTest asserts this file is
        // the only place a schedule is declared, so an entry cannot appear
        // somewhere this table does not describe.
    }
}
