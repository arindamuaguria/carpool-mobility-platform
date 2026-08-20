<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `CMP-IMP-051` — `op_users`, `op_user_credentials` and `op_sessions`.
 *
 * The first `op_` tables, and the first realisation of an aggregate from
 * `BE-017`. They became decidable on 2026-08-20, when the Project Owner took
 * three decisions and one policy value that between them fixed every column
 * below:
 *
 * - **`BAD-RULE-043`** — the mandatory identifying detail is the **verified
 *   phone number and nothing else**. No name, email address, date of birth or
 *   address column appears here, and none may be added on the ground that it may
 *   be useful (`SEC-089`, `SEC-090`).
 * - **`BAD-RULE-006`** — the verification standing vocabulary is `UNVERIFIED`
 *   and `VERIFIED`.
 * - **`BAD-RULE-010`** — the account states are `ACTIVE`, `SUSPENDED` and
 *   `DEACTIVATED`.
 * - **`SEC-025`** — five attempts per number per hour and **no account
 *   lockout**, which is why there is no locked-until column: a lockout would be
 *   a condition of the account, and a rate bound is a question asked of the
 *   attempts `DB-043` retains.
 *
 * ## What is deliberately absent
 *
 * `DB-039` / `DB-045`: `verification_standing` and `account_state` are **strings,
 * not database `ENUM`s**, so the value set can change without a schema
 * migration. There is no `CHECK` listing the values either — that would be an
 * `ENUM` by another name, and `DB-214` ‡ forbids a migration encoding a business
 * rule. Both sets are absolute business rules and live in the Domain, where
 * `BE-012` puts a rule that must not be reachable for override.
 *
 * `op_user_verifications` and `op_user_emergency_contacts` are **not created
 * here**. CMP-DOC-11 §6.2 lists them among the identity tables, but
 * `CMP-IMP-051` scopes three, and the attempts table belongs with the
 * verification flow that reads it.
 *
 * No `expires_at` on a session. `SEC-039` makes the session lifetime **policy
 * configuration**, and `BE-170` invalidates the policy cache on change so that no
 * restart is required; an expiry frozen into the row at establishment would make
 * a shortened lifetime not apply to the sessions it was shortened for. The bound
 * is evaluated against `established_at` when the session is validated. **Its
 * value is undecided and no code evaluates it yet.**
 *
 * ## Protection at rest — why the phone number is not a protected column
 *
 * `DB-035` ‡ marks as protected the columns holding **identity evidence or
 * payment-related data**. A phone number is personal data, and it is neither of
 * those: `BAD-DEC-005` leaves identity evidence undecided and `BAD-RULE-043`
 * collects none. `SEC-081` ‡ and `SEC-083` ‡ each extend protection to a further
 * category by naming it — message content, position history — which they would
 * not need to do if protection reached all personal data, and neither names a
 * phone number.
 *
 * `SEC-079` settles it: *"a protected column cannot be searched or indexed on its
 * content."* `FRD-FR-004` refuses a registration whose number is already
 * registered, and `DB-209` ‡ requires a rule enforceable by the database to be
 * enforced by it — which needs the unique index below. The two statements cannot
 * both be met by a protected column, so the phone number is not one.
 *
 * `DB-176` / `DB-177`: `phone_number` is the one personal column here and its
 * retention classification is **tokenisable**. `DADR-12` removes personal data by
 * nulling or tokenising in place and never by deleting a row; the number cannot
 * be nulled, because it is what identifies the account. The retention **period**
 * is `BAD-DEC-021` and is not set.
 */
return new class extends Migration
{
    private const USERS = 'op_users';

    private const CREDENTIALS = 'op_user_credentials';

    private const SESSIONS = 'op_sessions';

    public function up(): void
    {
        Schema::create(self::USERS, function (Blueprint $table): void {
            // DB-021: the clustering key. DB-024 ‡: never exposed.
            $table->id();

            // DB-022 ‡ / DB-023 ‡: a user is exposed through the interface, so it
            // carries a unique, indexed, randomly generated external identifier
            // encoding no meaning, no sequence and no timestamp. FRD-FR-005 ‡
            // makes it unique, persistent and never reused.
            $table->char('external_id', 32)->unique('op_users_external_id_unique');

            // BAD-RULE-043: the one mandatory identifying detail.
            //
            // FRD-FR-004 refuses a registration whose number is already
            // registered, and DB-028 enforces a natural key by a unique
            // constraint. FRD-FR-013 permits registration to be restarted with
            // the same number after abandonment — which this constraint shapes
            // toward reusing the existing unverified account rather than creating
            // a second one. That is a registration-flow consequence, recorded
            // here and decided there.
            $table->string('phone_number', 32)->unique('op_users_phone_number_unique');

            // DB-041 ‡: written only by the platform, with no inbound write path
            // from the interface. API-125 ‡ makes it readable and never writable,
            // and API-037 ‡ keeps it out of every request schema — which
            // AuthoritativeValues already enforces.
            $table->string('verification_standing', 32);

            // DB-045 / BAD-RULE-010.
            $table->string('account_state', 32);

            // DB-034: the instant, in the single time zone reference.
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });

        Schema::create(self::CREDENTIALS, function (Blueprint $table): void {
            $table->id();

            // DB-017: named for the referenced entity followed by _id.
            $table->unsignedBigInteger('user_id');

            // DB-042 ‡ / SEC-016 ‡ / SEC-076 ‡: non-recoverable, and never on
            // op_users. SEC-028 ‡ stores it under a memory-hard, salted, tunable
            // hash whose encoded form carries its own salt (SEC-029 ‡) and cost
            // parameters (SEC-030) — which is what lets SEC-032 ‡ compare a
            // stored value against the current setting without a second column.
            //
            // SEC-031's cost parameters are undecided. Nothing writes this column
            // yet, and the column's shape does not depend on them.
            $table->string('material', 255);

            // SEC-017: the demonstration has a bounded lifetime, and the bound is
            // policy configuration. Stored as the instant it was issued rather
            // than the instant it expires, for BE-170's reason — a bound changed
            // in configuration must apply to what is already outstanding.
            $table->dateTime('issued_at', 6);

            // SEC-018 ‡: single-use. Acceptance sets this, and a demonstration
            // already carrying it is spent. Nullable because most are unspent
            // when written, and DB-109 ‡'s discipline applies — a column is
            // NOT NULL only where a value always exists.
            $table->dateTime('consumed_at', 6)->nullable();

            // DB-029 / DB-030 ‡: enforced, and RESTRICT. op_ holds records a
            // party is entitled to as evidence, so no delete cascades into it —
            // DADR-12 removes personal data in place instead.
            $table->foreign('user_id', 'op_user_credentials_user_id_foreign')
                ->references('id')
                ->on(self::USERS)
                ->restrictOnDelete();
        });

        Schema::create(self::SESSIONS, function (Blueprint $table): void {
            $table->id();

            // SEC-044 ‡: bound to the actor it authenticated, and not
            // transferable. The binding is this column and nothing else carries
            // it — SEC-045 ‡ keeps the session itself free of any authorisation
            // claim, so entitlement is evaluated against platform state on every
            // request rather than read from here.
            $table->unsignedBigInteger('user_id');

            // SEC-036 ‡: a hash of the token, never the token. SEC-042 makes
            // validation a hash-and-lookup, so the column is unique and indexed
            // by that constraint. Binary, so no collation folds two distinct
            // hashes into one.
            $table->binary('token_hash', 32)->unique('op_sessions_token_hash_unique');

            $table->dateTime('established_at', 6);

            // SEC-040 ‡ / DB-044 ‡: a terminated session is recorded as
            // terminated and is not removed, so that reuse is detectable rather
            // than merely impossible. SEC-048 ‡ then refuses a terminated,
            // expired or unknown token identically.
            //
            // SEC-047 retains the record for the period DB-169 sets, which is
            // policy configuration and unset. Nothing prunes this table.
            $table->dateTime('terminated_at', 6)->nullable();

            // SEC-046 terminates all of a user's sessions as one operation and
            // SEC-049 bounds how many may be concurrent; both count or sweep by
            // user, which is this index's justification (DB-216).
            $table->index('user_id', 'op_sessions_user_id_index');

            $table->foreign('user_id', 'op_sessions_user_id_foreign')
                ->references('id')
                ->on(self::USERS)
                ->restrictOnDelete();
        });
    }

    /**
     * `DB-213`: migrations are forward-only.
     *
     * `DB-219` ‡ and `DB-030` ‡ apply with force here. `op_users` is the root of
     * the operational schema, and dropping it would take the credentials and
     * sessions that reference it — which is precisely the cascade `DB-030` ‡
     * forbids a foreign key from performing. There is no `down()`.
     */
};
