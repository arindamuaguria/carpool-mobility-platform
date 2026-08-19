<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `CMP-IMP-031` — the typed, versioned policy configuration store.
 *
 * `DB-008` / `ARCH-146`: policy configuration lives in `cfg_`.
 *
 * `DB-152` / `BE-167`: **versioned records, each change producing a new version
 * rather than an update in place.** `cfg_policy_values` holds the declared key;
 * `cfg_policy_versions` holds every version that has ever applied, with the
 * actor who applied it and the value it replaced (`BE-173`). Updating in place
 * would leave no way to answer what the cancellation window was when a
 * particular booking was refused, which is what `BE-167` exists to make
 * answerable.
 *
 * **Neither table carries a current-version pointer.** The value in force is the
 * highest version, so applying a change is a single insert and nothing is ever
 * updated. That lets the application account hold `SELECT` and `INSERT` on
 * `cfg_` and neither `UPDATE` nor `DELETE`, which makes `DB-152` a property of
 * the credential rather than of the code — the technique `DADR-09` uses for
 * evidence.
 *
 * **`DADR-09` states no grant for the configuration domain.** It names
 * operational, projection and machinery as read-write and evidential and ledger
 * as append-only, and is silent on the sixth of `DB-002`. The narrowest reading
 * consistent with `BE-169` — the application reads policy on nearly every
 * decision — and with `DB-152` is applied here and reported as a documentation
 * gap.
 *
 * `DB-153` ‡ is enforced **above** this schema, not in it: a value that could
 * relax an absolute rule is absent from the register, so no row for it can be
 * created. A `CHECK` here would be validating a value the table should never
 * have been able to hold.
 *
 * `DB-033` ‡: values are stored as text and never as a floating-point type. A
 * policy value may be monetary — a commission, a fee, a threshold — and
 * `PolicyType::Decimal` is carried exactly, never converted.
 *
 * `DB-039`: an enumerated column whose value set is a business decision is not a
 * database `ENUM`. `value_type` is not such a column: it enumerates the
 * platform's own declared types (`BE-166`), which are code.
 *
 * `DB-040` / `DADR-16` — does interpretation depend on the interface version?
 * **No.** A policy value is read by the platform to make a decision; it is not
 * an interface representation, and no caller is shown one.
 */
return new class extends Migration
{
    private const VALUES = 'cfg_policy_values';

    private const VERSIONS = 'cfg_policy_versions';

    public function up(): void
    {
        Schema::create(self::VALUES, function (Blueprint $table): void {
            // DB-021. DB-024 ‡: never exposed. A policy value is not an entity
            // exposed through the interface, so DB-022 ‡ requires no external
            // identifier.
            $table->id();

            // The register's key. Unique: one declaration per value.
            $table->string('policy_key', 128)->unique('cfg_policy_values_policy_key_unique');

            // BE-166: the declared type, validated on write and enforced on read.
            $table->string('value_type', 16);

            $table->dateTime('created_at');
        });

        Schema::create(self::VERSIONS, function (Blueprint $table): void {
            $table->id();

            // DB-017: named for the referenced entity followed by _id.
            $table->unsignedBigInteger('policy_value_id');

            $table->unsignedInteger('version');

            // DB-033 ‡: text, never a floating-point type.
            $table->text('value_text');

            // BE-173: a policy change is evidenced with actor, previous value and
            // new value. The evidential record itself is CMP-IMP-439; these
            // columns carry what it will record, and carry it now.
            $table->text('previous_value_text')->nullable();
            $table->string('applied_by', 64);
            $table->dateTime('applied_at');

            // One version number per value, so a concurrent change cannot produce
            // two version 4s. The database decides, not a read.
            $table->unique(
                ['policy_value_id', 'version'],
                'cfg_policy_versions_policy_value_id_version_unique',
            );

            // DB-029: foreign keys declared and enforced. RESTRICT on delete —
            // DB-030 ‡ forbids cascading a delete into a record another party is
            // entitled to as evidence, and a policy history is exactly that.
            $table->foreign('policy_value_id', 'cfg_policy_versions_policy_value_id_foreign')
                ->references('id')
                ->on(self::VALUES)
                ->restrictOnDelete();
        });
    }

    /**
     * `DB-213`: migrations are forward-only. Dropping these would destroy the
     * audited history of every policy change (`ARCH-146`), which is a `DB-218` ‡
     * decision for the Project Owner rather than a routine operation.
     */
};
