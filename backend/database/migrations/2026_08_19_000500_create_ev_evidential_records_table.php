<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `CMP-IMP-438`, `CMP-IMP-440` — the append-only, chained evidential log.
 *
 * `DB-004` ‡: evidential records reside in `ev_` and are written **only** by the
 * evidential writer. `DB-113` ‡ says the same of the component; this migration
 * says it of the schema.
 *
 * `DB-109` ‡: `NOT NULL` on actor, action, subject, outcome and time — and
 * **not** on reason, because a successful operation has none to give.
 * `BE-114` ‡ requires one where the operation was refused, which is enforced in
 * `Evidence` rather than here: a `CHECK` cannot see that `outcome = 'refused'`
 * implies a non-empty reason without encoding a rule, and `DB-214` ‡ forbids a
 * migration encoding a business rule.
 *
 * `DB-110` ‡: `NOT NULL` on previous hash and record hash. The first record
 * chains to a fixed genesis value rather than to null, so the column can be
 * `NOT NULL` and the chain has no special first case to get wrong.
 *
 * `DB-111` ‡ / `SEC-109` ‡: the chain is ordered by the internal primary key,
 * which is monotonic and never reused.
 *
 * ## Append-only, in two layers
 *
 * `DB-118` ‡ / `BE-110` ‡ / `DADR-09`: the **primary** mechanism is the withheld
 * privilege — the application account holds `SELECT` and `INSERT` on `ev_` and
 * neither `UPDATE` nor `DELETE`. `SEC-110` ‡ is explicit that this remains the
 * primary defence.
 *
 * `DB-120` ‡: *"A trigger rejecting `UPDATE` and `DELETE` shall additionally be
 * present, as a second layer and not as the primary mechanism."* The triggers
 * below are that second layer. They defend against a credential that should not
 * have the privilege and does — including the migration account, which does.
 *
 * `DB-119` ‡ closes the circle: the application holds no `DDL`, so it cannot
 * drop the trigger that constrains it. `DADR-09` rejected triggers as the sole
 * mechanism for exactly that reason.
 *
 * `DB-116`: the evidential log is not a query source for operational screens.
 * The one index exists for investigation — `FRD-FR-250` requires records to be
 * available to operators for inspection and for demonstrating how a matter was
 * handled — and not to serve a screen.
 *
 * `DB-040` / `DADR-16` — does interpretation depend on the interface version?
 * **No.** An evidential record is the platform's own account of what it did; it
 * is not a representation any caller was shown.
 *
 * **Retention is unset.** `DB-117` and `BE-118` record it as
 * `[TBD – Business Decision Required]` (`GAP-012`, `BAD-DEC-021`), and `DB-125` ‡
 * forbids retention removal deleting a record at all, because deletion would
 * break the chain. `DB-126` ‡ resolves that by tokenising in place under a
 * fourth, tightly scoped privilege — which `SEC-117` ‡ requires not to be the
 * application account. None of that is built here; no retention process exists.
 */
return new class extends Migration
{
    private const TABLE = 'ev_evidential_records';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            // DB-021, DB-111 ‡, SEC-109 ‡: monotonic, never reused, and the chain
            // order. DB-024 ‡: never exposed.
            $table->id();

            // BE-107 ‡ / DB-109 ‡ / FRD-FR-245 ‡ — the party responsible.
            // FRD-FR-246 ‡: where an operator acted, this is the operator.
            // An opaque reference (DB-024 ‡), never a name or a contact detail
            // (BE-201 ‡).
            $table->string('actor', 64);

            // What occurred.
            $table->string('action', 128);

            // Its subject, as an external identifier (DB-022 ‡, DB-024 ‡).
            $table->string('subject', 191);

            // DB-039: not a database ENUM. The value set is the platform's own
            // observation of its operation, so it is code — but a constrained
            // string leaves room for that judgement to be revisited without a
            // schema change.
            $table->string('outcome', 32);

            // BE-114 ‡ requires this where the operation was refused. Nullable,
            // because DB-109 ‡ deliberately omits it from the NOT NULL list.
            $table->string('reason', 191)->nullable();

            // DB-034: the instant, in the single time zone reference.
            $table->dateTime('occurred_at', 6);

            // SEC-105 ‡ / DB-110 ‡: a keyed hash over this record's content and
            // its predecessor's hash. Binary, so no collation can fold two
            // distinct hashes into one.
            $table->binary('previous_hash', 64);
            $table->binary('record_hash', 64);

            // SEC-174: an algorithm choice must be replaceable without a
            // migration that cannot be staged. Recording which construction
            // produced each hash is what makes a staged replacement possible.
            $table->string('chain_algorithm', 32);

            // One successor per record — which is what a chain **is**.
            //
            // The writer computes the keyed hash in PHP, because SEC-106 ‡ keeps
            // the key out of the database, so it must read the predecessor before
            // it inserts. A locking read would serialise two concurrent writers,
            // and DB-118 ‡ makes one impossible: MySQL refuses SELECT ... FOR
            // UPDATE without the UPDATE privilege, which the application account
            // does not have and must not have.
            //
            // So the database decides instead. Two records claiming the same
            // predecessor are a fork, and this constraint refuses the second —
            // the same technique DB-142 ‡ chooses for the idempotency registry,
            // and for the same stated reason: rejected by the database rather
            // than by a race-prone read.
            $table->unique('previous_hash', 'ev_evidential_records_previous_hash_unique');

            // FRD-FR-250: for operator inspection. DB-116: not for a screen.
            $table->index(['subject', 'occurred_at'], 'ev_evidential_records_subject_occurred_at_index');
            $table->index(['actor', 'occurred_at'], 'ev_evidential_records_actor_occurred_at_index');
        });

        // DB-120 ‡: the second layer. SEC-110 ‡ keeps the withheld privilege as
        // the primary one; this catches a credential that has the privilege and
        // should not use it.
        DB::unprepared(
            'CREATE TRIGGER ev_evidential_records_refuse_update BEFORE UPDATE ON '.self::TABLE.' FOR EACH ROW '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            ."'DB-108 : an evidential record is never updated by any code path.'"
        );

        DB::unprepared(
            'CREATE TRIGGER ev_evidential_records_refuse_delete BEFORE DELETE ON '.self::TABLE.' FOR EACH ROW '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = "
            ."'DB-108 : an evidential record is never deleted by any code path.'"
        );
    }

    /**
     * `DB-213`: migrations are forward-only, and this one especially.
     *
     * `DB-123`: migration against the evidential domain is **additive**; no
     * migration rewrites an existing record. `DB-219` ‡ states it absolutely.
     * Dropping this table would destroy the platform's account of everything it
     * has ever done, which is not a routine operation and not one a `down()`
     * should make available.
     */
};
