<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `CMP-IMP-027` — the job store behind the seven families.
 *
 * `DB-007` / `DADR-13`: job state is machinery, so it lives in `mch_`.
 * `DB-013`: no foreign key into `op_` — a job names the identity it acts for
 * (`BE-140`) inside its payload, and the store stays prunable without cascading
 * into authoritative state (`DB-156`).
 *
 * `DB-149`: deferred work survives restart, which follows from the store being a
 * transactional InnoDB table (`DADR-02`) rather than process memory — the same
 * property `BE-141` requires of a job.
 *
 * `DB-142` ‡ has no counterpart here: a job is not idempotent by virtue of a
 * constraint. `BE-135` ‡ makes safe re-execution the job's own obligation, and
 * that is `CMP-IMP-028`.
 *
 * `DB-034` asks for the instant rather than a local wall-clock reading. The
 * three time columns are Unix timestamps — an instant against a single
 * reference, the epoch — which is the form the database queue driver reads and
 * writes. They are not wall-clock readings and carry no time zone to be wrong
 * about.
 *
 * `DB-040` / `DADR-16` — does this table's interpretation depend on the
 * interface version? **No.** A payload is an internal serialisation between the
 * enqueuing process and the worker, never an interface representation; nothing
 * a caller sent or will be shown is stored here. Contrast
 * `mch_idempotency_registries`, which records an outcome the interface produced
 * and therefore does carry the version.
 */
return new class extends Migration
{
    private const JOBS = 'mch_jobs';

    private const FAILED_JOBS = 'mch_failed_jobs';

    public function up(): void
    {
        Schema::create(self::JOBS, function (Blueprint $table): void {
            // DB-021. DB-024 ‡: never exposed. DB-022 ‡ requires an external
            // identifier only for an entity exposed through the interface; a job
            // is machinery and is exposed to no caller.
            $table->id();

            // BE-131: the family, which is also the queue. One family, one queue.
            $table->string('queue', 64);

            // BE-141: everything the job needs, so that it depends on no memory
            // state of the process that enqueued it. BE-140: the identity it acts
            // for is carried in here explicitly.
            $table->longText('payload');

            // DB-145: attempt count. BE-139 keeps the *limit* in configuration,
            // not in code; this is the count against it.
            $table->unsignedTinyInteger('attempts');

            $table->unsignedInteger('reserved_at')->nullable();

            // DB-145: the availability instant.
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');

            // DB-146 ‡: safety-family work is selectable **without scanning other
            // families**, so that it is never delayed by their volume — the
            // storage half of BE-132 ‡. DB-148: queue depth and oldest-item age
            // are derivable per family from the same index.
            $table->index(['queue', 'available_at'], 'mch_jobs_queue_available_at_index');
        });

        // DB-147 / BE-137: a job exhausting its attempts moves to a failed state
        // visible to operations. It is **not discarded** — a safety job that
        // silently vanished would be BD-04 broken in the quietest possible way,
        // and BE-138 ‡ requires a failed safety job to raise an operational
        // condition immediately.
        Schema::create(self::FAILED_JOBS, function (Blueprint $table): void {
            $table->id();
            $table->string('uuid', 64)->unique('mch_failed_jobs_uuid_unique');
            $table->text('connection');
            $table->string('queue', 64);
            $table->longText('payload');
            $table->longText('exception');
            $table->dateTime('failed_at');

            // Operations reads this by family and by time.
            $table->index(['queue', 'failed_at'], 'mch_failed_jobs_queue_failed_at_index');
        });
    }

    /**
     * `DB-213`: migrations are forward-only. Reversing this would drop the store
     * that makes deferred work survive a restart, and dropping a table is a
     * `DB-218` ‡ decision for the Project Owner rather than a routine operation.
     */
};
