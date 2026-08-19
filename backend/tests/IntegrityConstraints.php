<?php

declare(strict_types=1);

namespace Tests;

/**
 * The twenty-one constraints of CMP-DOC-11 §15, as one list.
 *
 * `DB-212` is why this is one file rather than a comment beside each migration:
 * *"the register shall be reviewable as a single list, which is why it appears
 * here in full rather than distributed through the schema."*
 *
 * `DB-207` ‡ is what each entry owes: *"each of the twenty-one constraints shall
 * have a test that attempts to violate it and requires the database to refuse."*
 * `DB-205` ‡ adds that three of them — 8, 9 and 10 — are **grants, not schema
 * objects**, and are asserted the same way: by attempting the forbidden
 * operation.
 *
 * ## Eleven hold; ten do not, and each says why
 *
 * The register ships complete and mostly unenforced, which is the honest state
 * and not a gap. Ten constraints protect tables that do not exist, because
 * `BE-017`'s nine aggregates are unbuilt and several are blocked on a business
 * decision nobody has taken. `DB-208` requires a constraint added later to reach
 * this register **and its test set in the same change**, so an entry moving from
 * absent to enforced is one edit, in one place, that the build checks.
 *
 * **Constraint 18 is different from the other nine.** It is not waiting: ratings
 * is a withheld area carrying zero functional requirements (CMP-DOC-04 §9.2), and
 * `CC-025` records that CMP-DOC-11 §6.7 and CMP-DOC-10 §11.9 specify a ratings
 * table and endpoint that must not be followed. `ADM-187`/`ADM-191` forbid
 * building a withheld item even disabled or flagged, so constraint 18 must never
 * become enforced without the Project Owner reopening the area. It is recorded
 * here so that its absence reads as a decision rather than as an oversight.
 */
final class IntegrityConstraints
{
    public const ENFORCED = 'enforced';

    public const ABSENT = 'absent';

    /**
     * `CC-025`: specified upstream, and not to be built.
     */
    public const WITHHELD = 'withheld';

    /**
     * @return array<int, array{
     *     constraint: string,
     *     protects: string,
     *     status: string,
     *     kind: string,
     *     object: ?string,
     *     violationTest: ?string,
     *     note: string,
     * }>
     */
    public static function all(): array
    {
        return [
            1 => [
                'constraint' => 'CHECK seats confirmed between 0 and seats offered',
                'protects' => 'BAD-RULE-027, DB-083',
                'status' => self::ABSENT,
                'kind' => 'check',
                'object' => null,
                'violationTest' => null,
                'note' => 'op_rides does not exist. BE-017 fixes the Ride aggregate and BAD-DEC-007 (booking model) is open.',
            ],
            2 => [
                'constraint' => 'UNIQUE one allocation row per ride',
                'protects' => 'DB-079',
                'status' => self::ABSENT,
                'kind' => 'unique',
                'object' => null,
                'violationTest' => null,
                'note' => 'The seat allocation table arrives with the Ride aggregate; BAD-DEC-007 is open.',
            ],
            3 => [
                'constraint' => 'CHECK payment status in the three permitted values',
                'protects' => 'SRS-REQ-155, DB-065',
                'status' => self::ABSENT,
                'kind' => 'check',
                'object' => null,
                'violationTest' => null,
                'note' => 'op_payments does not exist. FEAT-016 brings it; BAD-DEC-003 (fare) is open.',
            ],
            4 => [
                'constraint' => 'UNIQUE external identifier per exposed table',
                'protects' => 'API-014, DB-022',
                'status' => self::ABSENT,
                'kind' => 'unique',
                'object' => null,
                'violationTest' => null,
                'note' => 'No table is exposed through an interface yet; FEAT-035 brings the first.',
            ],
            5 => [
                'constraint' => 'UNIQUE idempotency actor, operation, key',
                'protects' => 'BADR-08, DB-142',
                'status' => self::ENFORCED,
                'kind' => 'unique',
                'object' => 'mch_idempotency_registries_actor_operation_request_key_unique',
                'violationTest' => 'tests/Integration/Persistence/IdempotencyRegistryTest.php',
                'note' => 'DB-142 ‡ makes the constraint the decider rather than a read; the losing writer is refused.',
            ],
            6 => [
                'constraint' => 'NOT NULL on actor, action, subject, outcome, time in evidential records',
                'protects' => 'BE-107, DB-109',
                'status' => self::ENFORCED,
                'kind' => 'not_null',
                'object' => 'ev_evidential_records.actor,action,subject,outcome,occurred_at',
                'violationTest' => 'tests/Integration/Evidence/EvidentialLogTest.php',
                'note' => 'reason is deliberately nullable; DB-109 ‡ omits it and BE-114 ‡ is enforced in Evidence.',
            ],
            7 => [
                'constraint' => 'NOT NULL on previous hash and record hash',
                'protects' => 'ARCH-054, DB-110',
                'status' => self::ENFORCED,
                'kind' => 'not_null',
                'object' => 'ev_evidential_records.previous_hash,record_hash',
                'violationTest' => 'tests/Integration/Evidence/EvidentialLogTest.php',
                'note' => 'The first record chains to a genesis value, so the column is NOT NULL with no special first case.',
            ],
            8 => [
                'constraint' => 'No UPDATE or DELETE privilege on ev_ for the application account',
                'protects' => 'BE-110, DB-118',
                'status' => self::ENFORCED,
                'kind' => 'grant',
                'object' => null,
                'violationTest' => 'tests/Integration/Evidence/EvidentialLogTest.php',
                'note' => 'DB-205 ‡: a grant, not a schema object, asserted by attempting the forbidden operation.',
            ],
            9 => [
                'constraint' => 'No UPDATE or DELETE privilege on led_ for the application account',
                'protects' => 'BE-096, DB-094',
                'status' => self::ENFORCED,
                'kind' => 'grant',
                'object' => null,
                'violationTest' => null,
                'note' => 'The grant is issued — GrantPlan holds led_ among the append-only domains — and asserted at the '
                    .'grant level by DatabaseAccountGrantsTest. DB-205 ‡ wants the forbidden operation attempted, and '
                    .'that needs a led_ table; none exists, because the ledger arrives with the payment aggregates.',
            ],
            10 => [
                'constraint' => 'No DDL privilege for the application account',
                'protects' => 'DB-119',
                'status' => self::ENFORCED,
                'kind' => 'grant',
                'object' => null,
                'violationTest' => 'tests/Integration/Persistence/DatabaseAccountGrantsTest.php',
                'note' => 'DB-205 ‡, and DB-119 is what stops the application dropping the trigger that constrains it.',
            ],
            11 => [
                'constraint' => 'Trigger rejecting UPDATE and DELETE on ev_',
                'protects' => 'DB-120',
                'status' => self::ENFORCED,
                'kind' => 'trigger',
                'object' => 'ev_evidential_records_refuse_update,ev_evidential_records_refuse_delete',
                'violationTest' => 'tests/Integration/Evidence/EvidentialLogTest.php',
                'note' => 'The second layer; SEC-110 ‡ keeps the withheld privilege as the primary one. OPS-209 ‡ '
                    .'records the server configuration this needs under binary logging.',
            ],
            12 => [
                'constraint' => 'NOT NULL on ledger party, event, direction, amount',
                'protects' => 'NFR-128, DB-095',
                'status' => self::ABSENT,
                'kind' => 'not_null',
                'object' => null,
                'violationTest' => null,
                'note' => 'No led_ table exists.',
            ],
            13 => [
                'constraint' => 'CHECK ledger amount is exact decimal and non-null',
                'protects' => 'DB-100',
                'status' => self::ABSENT,
                'kind' => 'check',
                'object' => null,
                'violationTest' => null,
                'note' => 'No led_ table, and the scale is undecided: DB-032 monetary precision is GAP-016 and was '
                    .'expressly excluded from the ratified technical decisions (T5).',
            ],
            14 => [
                'constraint' => 'FOREIGN KEY booking to ride request, ride, user',
                'protects' => 'DB-029',
                'status' => self::ABSENT,
                'kind' => 'foreign_key',
                'object' => null,
                'violationTest' => null,
                'note' => 'Three of BE-017 nine aggregates; BAD-DEC-007 is open.',
            ],
            15 => [
                'constraint' => 'RESTRICT on delete for any referenced operational row',
                'protects' => 'DB-030, DB-048',
                'status' => self::ENFORCED,
                'kind' => 'foreign_key',
                'object' => 'cfg_policy_versions_policy_value_id_foreign',
                'violationTest' => 'tests/Integration/Persistence/InspectsSchemaConventionsTest.php',
                'note' => 'CMP-IMP-048 made this a rule over the whole schema rather than a property of one key: the '
                    .'inspector refuses ON DELETE CASCADE or SET NULL into op_, ev_ or led_.',
            ],
            16 => [
                'constraint' => 'CHECK seats offered does not exceed vehicle lawful capacity at insert',
                'protects' => 'BE-023, DB-081',
                'status' => self::ABSENT,
                'kind' => 'check',
                'object' => null,
                'violationTest' => null,
                'note' => 'op_vehicles and op_rides do not exist.',
            ],
            17 => [
                'constraint' => 'NOT NULL on captured values of a completed trip',
                'protects' => 'NFR-126, DB-158',
                'status' => self::ABSENT,
                'kind' => 'not_null',
                'object' => null,
                'violationTest' => null,
                'note' => 'op_trips does not exist.',
            ],
            18 => [
                'constraint' => 'UNIQUE one rating per rater per trip',
                'protects' => 'FRD-FR-172',
                'status' => self::WITHHELD,
                'kind' => 'unique',
                'object' => null,
                'violationTest' => null,
                'note' => 'MUST NOT BE BUILT. Ratings carries zero functional requirements (CMP-DOC-04 §9.2) and '
                    .'CC-025 records that CMP-DOC-11 §6.7 and CMP-DOC-10 §11.9 specify a table and an endpoint for it '
                    .'in error. ADM-187/ADM-191 forbid a withheld item even disabled or flagged.',
            ],
            19 => [
                'constraint' => 'NOT NULL on job family and availability instant',
                'protects' => 'DB-145',
                'status' => self::ENFORCED,
                'kind' => 'not_null',
                'object' => 'mch_jobs.queue,available_at',
                'violationTest' => 'tests/Integration/Persistence/WorkQueueTest.php',
                'note' => 'The queue column carries the family; BE-131 keeps the seven in one enum and forbids an eighth.',
            ],
            20 => [
                'constraint' => 'NOT NULL on policy value version',
                'protects' => 'DB-152',
                'status' => self::ENFORCED,
                'kind' => 'not_null',
                'object' => 'cfg_policy_versions.version',
                'violationTest' => 'tests/Integration/Policy/PolicyStoreTest.php',
                'note' => 'DB-152 makes each change a new version; the unique on (policy_value_id, version) decides a race.',
            ],
            21 => [
                'constraint' => 'No foreign key from any domain into proj_',
                'protects' => 'DB-012',
                'status' => self::ENFORCED,
                'kind' => 'schema_rule',
                'object' => null,
                'violationTest' => 'tests/Integration/Persistence/InspectsSchemaConventionsTest.php',
                'note' => 'Enforced by inspection rather than by a schema object, because it is a rule about the '
                    .'absence of one. ARCH-114: losing a projection degrades performance, never correctness.',
            ],
        ];
    }
}
