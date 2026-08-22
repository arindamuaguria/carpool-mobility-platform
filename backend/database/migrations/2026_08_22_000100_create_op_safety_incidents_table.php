<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `op_safety_incidents` — `UC-051`, and the reason the safety package is not
 * blocked.
 *
 * CMP-DOC-02 states the distinction in terms: `BRD-REQ-110`, `111`, `112` and
 * `115` — *record, capture, route, record the response* — are **Ready**, and
 * what cannot be built is `BRD-REQ-113`, the protocol, and `BRD-REQ-109`, the
 * control that invokes it. `UC-051` says the same: *"Everything here — capture,
 * context, routing, audit — is decided, buildable and testable today. It is the
 * foundation `BAD-DEC-011` will sit on."*
 *
 * This table is that foundation.
 *
 * ## Every column is nullable that can be, and that is the point
 *
 * `DB-077` ‡: *"A safety incident shall be insertable with **partial context**,
 * so that no validation failure on a non-essential column can lose a signal."*
 * `BD-04` is the driver behind it — no safety signal may be lost, under any load
 * or failure — and `API-164` ‡ carries it up to the request shape. A `NOT NULL`
 * on a context column would be a way for a signal to be refused, so there is
 * exactly one besides the identity columns: **who raised it**, which the session
 * supplies and without which there is no incident.
 *
 * ## The context standings, and why there are no value columns yet
 *
 * `DB-078` ‡: *"Missing context shall be recorded as missing, and shall be
 * distinguishable from context that was **absent in fact**."* Three states, not
 * two — `recorded`, `absent`, `unavailable` — and `UC-051` E3 is why the middle
 * one exists: a signal raised outside an active trip has no trip, which is not
 * the same as a trip the platform could not look up.
 *
 * `FRD-FR-186` ‡ names five context elements. One — the raising user — is always
 * known. The other four each have a **standing column and no value column**,
 * because every capability that could supply a verified value is unbuilt or
 * blocked:
 *
 * | Element | Why no value can be recorded today |
 * |---|---|
 * | Trip | `Trip` is one of `BE-017`'s nine aggregates and is unbuilt (FEAT-018). |
 * | Vehicle | `CC-035` — `BAD-DEC-005` and `FRD-OQ-004`/`005` leave the vehicle model undecided. |
 * | Co-travellers | Needs `Booking` and `Trip`; neither exists. |
 * | Location | GPS is FEAT-019, blocked on `BAD-DEC-021` and `FRD-OQ-009`. |
 *
 * A value column arrives with the capability that can fill it, which is this
 * project's standing rule — a policy key arrives with its reader, an
 * authorisation rule with its operation. `BE-181` ‡ is the sharper reason: a
 * value the platform cannot verify against its own state is a caller's claim,
 * and a column for one would invite storing it as though it were fact.
 *
 * **All four therefore stand at `unavailable` today**, which is true, checkable,
 * and exactly what `DB-078` ‡ asks the schema to be able to say.
 *
 * ## Closure
 *
 * `BE-029` ‡ / `API-171` ‡: an incident *"shall not close without a recorded
 * outcome"*, and *"shall not be closable through the client interface"*.
 * `closed_at` and `outcome` exist so the aggregate can express closure and the
 * invariant can be enforced against something. **Nothing closes an incident
 * today** — `UC-052` is Outlined pending `BAD-DEC-011`, the outcome vocabulary
 * is part of the protocol nobody has decided, and `ADM-168` records that the
 * operator unit cannot start. The columns are the shape of the rule, not an
 * affordance for it.
 *
 * ## `op_safety_actions` is not created here
 *
 * CMP-DOC-11 §6.10 gives `SafetyIncident` two tables. The second holds the
 * actions `BRD-REQ-115` requires recorded — and every action is an **operator's**,
 * with `BAD-DEC-006` leaving the role set undecided and `ADM-168` recording that
 * the administrative unit cannot start. A table with no writer is unbuilt work,
 * not a foundation, so it arrives with the surface that records into it.
 */
return new class extends Migration
{
    private const TABLE = 'op_safety_incidents';

    private const USERS = 'op_users';

    public function up(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            // DB-021: the clustering key. DB-024 ‡: never exposed.
            $table->id();

            // DB-022 ‡ / DB-023 ‡: an incident is read at
            // /safety/v1/incidents/{id}, so it carries its own randomly
            // generated external identifier. DB-189 indexes it.
            $table->char('external_id', 32)->unique('op_safety_incidents_external_id_unique');

            // FRD-FR-186 ‡'s first context element, and the only one that is
            // never unavailable: SEC-044 ‡ binds a session to exactly one actor,
            // so the platform knows who raised it without asking.
            $table->unsignedBigInteger('user_id');

            // DB-034: the instant, in the single time zone reference. The
            // instant of **raising**, which is what an operator reading the
            // queue needs, and it is the platform's clock rather than anything
            // the caller sent.
            $table->dateTime('raised_at', 6);

            // DB-078 ‡ — three states each, never two. See the class note for
            // why none of the four carries a value column yet.
            $table->string('trip_standing', 32);
            $table->string('vehicle_standing', 32);
            $table->string('co_travellers_standing', 32);
            $table->string('location_standing', 32);

            // BRD-REQ-112 / FRD-FR-189 ‡: routed to the operator queue. Null
            // until it reaches one, which is what makes FRD-FR-190 ‡'s "retain
            // and retry" answerable by a query rather than by a memory of what
            // was dispatched.
            $table->dateTime('routed_at', 6)->nullable();

            // BE-029 ‡ / API-171 ‡. Nothing writes either today.
            $table->dateTime('closed_at', 6)->nullable();
            $table->string('outcome', 255)->nullable();

            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);

            // The one query FRD-FR-190 ‡ needs: every incident that has not
            // reached the queue, oldest first.
            $table->index(['routed_at', 'raised_at'], 'op_safety_incidents_routed_at_raised_at_index');

            // DB-029 / DB-030 ‡: enforced, and RESTRICT. UC-051's minimal
            // guarantee — "no safety signal is ever lost" — would be defeated by
            // a cascade that removed incidents along with an account.
            $table->foreign('user_id', 'op_safety_incidents_user_id_foreign')
                ->references('id')
                ->on(self::USERS)
                ->restrictOnDelete();
        });
    }

    /**
     * `DB-213`: migrations are forward-only.
     *
     * `DB-219` ‡, and `UC-051`'s minimal guarantee with it: *"No safety signal is
     * ever lost, and none is closed without a record."* A `down()` here would be
     * a code path that loses every safety signal at once. There is none.
     */
};
