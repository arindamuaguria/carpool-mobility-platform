<?php

declare(strict_types=1);

use Cmp\Application\Shared\Work\JobFamily;

/*
 * The work subsystem.
 *
 * BADR-07 / BE-142: job storage is the relational database at launch, behind an
 * interface permitting substitution. Only two connections are configured — the
 * database driver the platform runs on, and `sync` for tests that need a job to
 * run inline. No broker is configured: ADR-06 deferred one, and configuring an
 * unused connection would suggest a supplier had been chosen.
 *
 * BE-131: the seven families are declared in JobFamily and nowhere else. This
 * file binds them; it does not name them, so an eighth cannot be introduced by
 * editing configuration.
 */

return [

    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            // The application account's connection. DB-007 puts job state in
            // mch_, where that account holds SELECT/INSERT/UPDATE/DELETE.
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => 'mch_jobs',
            // A job is always enqueued to a named family. Nothing may land on a
            // queue that is not one of the seven, so the fallback is the lowest
            // family rather than an eighth called "default".
            'queue' => JobFamily::Maintenance->queue(),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            // BE-057 ‡ and BADR-06 put dispatch after commit, and a job enqueued
            // inside a transaction that then rolls back describes work that was
            // never authorised. The framework's own guard is enabled as a second
            // layer behind UnitOfWork.
            'after_commit' => true,
        ],

    ],

    /*
     * DB-147 / BE-137: an exhausted job moves to a failed state visible to
     * operations. `null-driver` discarding is deliberately not used — a safety
     * job that vanished silently would break BD-04 in the quietest way there is.
     */
    'failed' => [
        'driver' => 'database-uuids',
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'mch_failed_jobs',
    ],

];
