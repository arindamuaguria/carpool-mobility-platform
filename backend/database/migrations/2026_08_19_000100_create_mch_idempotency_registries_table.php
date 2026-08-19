<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `CMP-IMP-025` — the idempotency registry at the application boundary.
 *
 * `DB-007`/`DADR-13`: the registry is machinery, so it lives in `mch_`.
 * `DB-013`: it holds **no foreign key into `op_`** — the actor is a recorded
 * reference, so that a machinery table can be pruned without cascading into
 * authoritative state (`DB-156`).
 *
 * `DB-214` ‡: a migration encodes no business rule. The unique constraint here
 * is a machinery invariant — one entry per actor, operation and key — not a rule
 * about rides, bookings or money.
 *
 * `DB-040`/`DADR-16` asks, of every new table, whether its interpretation can
 * change between interface versions. For this one it can: the recorded outcome
 * is a representation the interface produced, so the version is recorded with
 * it. A replay from an entry written under a different version can then be
 * recognised rather than silently re-served.
 *
 * **Retention is unset.** `DB-144` records the duration as
 * `[TBD – Technical Decision Required]` and requires it to exceed the longest
 * client retry window; `API-069`/`API-070` make it configuration, and
 * `API-070` warns that a key whose retention has lapsed is treated as unseen.
 * No pruning is built here, and none may be until the value is set — pruning on
 * a guessed period would silently reopen the duplicate window the registry
 * exists to close.
 */
return new class extends Migration
{
    private const TABLE = 'mch_idempotency_registries';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            // DB-021: BIGINT UNSIGNED AUTO_INCREMENT, used for clustering.
            // DB-024 ‡: it is never exposed. DB-022 ‡ requires an external
            // identifier only for an entity exposed through the interface; the
            // registry is machinery and is exposed to no caller, so it has none.
            $table->id();

            // API-060: the key is scoped to the acting actor and the operation.
            $table->string('actor', 64);
            $table->string('operation', 128);

            // API-059: caller-generated and opaque to the platform. Nothing here
            // parses it or derives meaning from it.
            $table->string('request_key', 128);

            // API-062 ‡ / API-063 ‡: what distinguishes a replay from a key
            // reused for a different request.
            $table->string('content_fingerprint', 128);

            // DB-040 / DADR-16.
            $table->unsignedSmallInteger('interface_version');

            // DB-143: the recorded outcome, so that a replay returns the original
            // rather than re-executing. Null until the guarded work completes,
            // and null for an outcome that carried no representation.
            $table->json('outcome')->nullable();

            // DB-034: instants, in one time zone reference.
            $table->dateTime('claimed_at');
            $table->dateTime('completed_at')->nullable();

            // DB-142 ‡: unique on actor, operation and key, so that a duplicate
            // is rejected by the database rather than by a race-prone read.
            // DB-019: the name states the rule and its columns.
            $table->unique(
                ['actor', 'operation', 'request_key'],
                'mch_idempotency_registries_actor_operation_request_key_unique',
            );
        });
    }

    /**
     * `DB-213`: migrations are forward-only.
     *
     * Reversing this migration would drop a table, which `DB-218` ‡ makes a
     * decision for the Project Owner rather than a routine operation — and the
     * registry is what prevents a duplicate charge on a retry. There is
     * therefore no `down`.
     */
};
