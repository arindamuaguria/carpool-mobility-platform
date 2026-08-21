<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `op_user_emergency_contacts` — the table `CMP-IMP-051` deliberately left out.
 *
 * CMP-DOC-11 §6.2 lists it among the identity tables with a one-line
 * description — *"Nominated contacts."* — and specifies **no column**. What a
 * contact consists of is therefore taken here, at the narrowest reading the
 * chain supports, and recorded through change control as `CC-044`.
 *
 * ## What a contact is, and why it is not more
 *
 * `BAD-RULE-043` is the governing precedent: the platform holds the **phone
 * number and nothing else** for the user themselves, and `SEC-089`/`SEC-090`
 * forbid a column added on the ground that it may one day be useful. A
 * nominated contact is a **third party who is not a user**, so that discipline
 * applies at least as strongly.
 *
 * - **`phone_number`** — the only detail the platform could ever use, and the
 *   only one `UC-048`'s success guarantee needs.
 * - **`label`**, *nullable* — `FRD-FR-183` validates contact *details* and
 *   `UC-048` A1 records a **set** the user manages. A set of bare numbers is one
 *   the user cannot tell apart. It is optional because no document requires it,
 *   and `DB-109` ‡'s discipline makes a column `NOT NULL` only where a value
 *   always exists.
 *
 * There is **no email column and no channel column**. `CC-034` records that no
 * delivery channel is selected, and the Project Owner directed in terms that one
 * must not be invented to unblock work. Nothing is sent to a contact
 * (`FRD-GAP-020`, `BAD-DEC-011`), so a column naming how would be an affordance
 * for behaviour that must not exist.
 *
 * There is **no relationship column**. It is unspecified, and it is a
 * third party's personal attribute — `BAD-DEC-022` is open.
 *
 * ## The unique constraint is the set
 *
 * `UC-048` A1: *"More than one may be nominated; the platform records the
 * **set**."* A set holds a number once. `(user_id, phone_number)` unique is that
 * sentence made structural, and it is the constraint that decides — `DB-142` ‡'s
 * pattern — when two requests nominate the same number at once.
 *
 * The composite is ordered `user_id` first so it also serves the only read this
 * table has: every contact of one user.
 *
 * ## What this table does not license
 *
 * `UC-048` states the position plainly: *"The platform can therefore hold
 * contacts today for a purpose it has not yet defined — which is acceptable only
 * because **nothing is sent to them** until that definition exists."* This table
 * holds. `BE-195` ‡ — notification through the highest-priority family — has no
 * implementation and must not acquire one here.
 */
return new class extends Migration
{
    private const TABLE = 'op_user_emergency_contacts';

    private const USERS = 'op_users';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            // DB-021: the clustering key. DB-024 ‡: never exposed.
            $table->id();

            // DB-022 ‡ / DB-023 ‡: a contact is addressed by the interface at
            // `/profile/emergency-contacts/{id}`, so it is an exposed entity and
            // carries its own randomly generated external identifier. DB-189
            // indexes it, because every inbound request resolves through it.
            $table->char('external_id', 32)->unique('op_user_emergency_contacts_external_id_unique');

            // DB-017: named for the referenced entity followed by _id.
            $table->unsignedBigInteger('user_id');

            // FRD-FR-184: recorded **against the user**.
            $table->string('phone_number', 32);

            // Nullable by DB-109 ‡: a contact without a label is still a contact.
            $table->string('label', 64)->nullable();

            // DB-034: the instant, in the single time zone reference.
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            // UC-048 A1 — the set. Two nominations of one number are one
            // nomination, and DB-142 ‡'s pattern makes the constraint decide it
            // rather than a read the second request would not see.
            $table->unique(['user_id', 'phone_number'], 'op_user_emergency_contacts_user_id_phone_number_unique');

            // DB-029 / DB-030 ‡: enforced, and RESTRICT. DADR-12 removes
            // personal data in place rather than by cascade — which matters more
            // here than anywhere, because the personal data is a third party's.
            $table->foreign('user_id', 'op_user_emergency_contacts_user_id_foreign')
                ->references('id')
                ->on(self::USERS)
                ->restrictOnDelete();
        });
    }

    /**
     * `DB-213`: migrations are forward-only.
     *
     * `DB-219` ‡. Dropping this table would discard third-party personal data the
     * user nominated, and `BAD-DEC-021` has decided no retention period against
     * which such a discard could be justified. There is no `down()`.
     */
};
