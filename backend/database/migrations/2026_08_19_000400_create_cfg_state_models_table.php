<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `CMP-IMP-032` — where the declared state models live.
 *
 * `DB-155`: *"State model definitions shall be held in `cfg_` and referenced by
 * the state columns of `op_`."* `DB-039`: an enumerated column whose value set is
 * a business decision **is not a database `ENUM`** — it references policy
 * configuration. Six of the ten models are undefined because a business decision
 * is open (CMP-DOC-06 §7.2), and an `ENUM` would need a migration every time one
 * of them was answered.
 *
 * `DB-152` applies here as it does to policy values: a change appends a version,
 * never an update in place. The definition in force is the highest version, so
 * the application account needs `SELECT` and `INSERT` on `cfg_` and nothing more
 * — see the note in `GrantPlan` about `DADR-09` being silent on this domain.
 *
 * The definition is JSON rather than a table of states and a table of
 * transitions. A model is read whole, validated whole (`BE-174`) and replaced
 * whole; splitting it across rows would make a half-applied model expressible,
 * and `BADR-13` already accepts that a misconfigured model can stall a workflow
 * without wanting to make a partially configured one possible as well.
 *
 * `DB-040` / `DADR-16` — does interpretation depend on the interface version?
 * **No.** A state model governs the platform's own lifecycle evaluation. What a
 * caller is shown about a state is a representation question and is decided
 * where representations are.
 *
 * **The table ships empty.** Seeding a model would be inventing business policy,
 * which is precisely what `BADR-13` rejected when it declined to hard-code the
 * proposed models: *"six are undecided; this is inventing business policy."*
 */
return new class extends Migration
{
    private const TABLE = 'cfg_state_models';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            // DB-021. DB-024 ‡: never exposed.
            $table->id();

            // The lifecycle this defines — one of the ten of CMP-DOC-06 §7.2.
            $table->string('model_name', 64);

            $table->unsignedInteger('version');

            // The permitted states and transitions, read and replaced whole.
            $table->json('definition');

            // BE-173 applies to a model change as to any other policy change.
            $table->string('applied_by', 64);
            $table->dateTime('applied_at');

            // One version number per model, decided by the database rather than
            // by a read — the same choice DB-142 ‡ makes for the idempotency
            // registry.
            $table->unique(
                ['model_name', 'version'],
                'cfg_state_models_model_name_version_unique',
            );
        });
    }

    /**
     * `DB-213`: migrations are forward-only. Dropping this would destroy the
     * audited history of every lifecycle definition the platform has run on,
     * which is a `DB-218` ‡ decision for the Project Owner.
     */
};
