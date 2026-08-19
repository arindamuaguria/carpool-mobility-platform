<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Cmp\Application\Shared\Schema\InspectsSchemaConventions;
use Cmp\Application\Shared\Schema\SchemaViolation;
use Cmp\Infrastructure\Persistence\Schema\SchemaConventionInspector;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\IntegrationTestCase;

/**
 * CMP-IMP-038, CMP-IMP-039, CMP-IMP-044, CMP-IMP-045 — the schema conventions,
 * checked against a real server.
 *
 * Level 3 (`TC-030` ‡): a real MySQL, never an in-memory substitute. The engine,
 * the collation and the constraint metadata this asserts do not exist in a
 * substitute, so the level-2 rules alone would prove nothing about the deployed
 * schema.
 */
final class InspectsSchemaConventionsTest extends IntegrationTestCase
{
    private const OFFENDING_TABLE = 'legacy_city_records';

    protected function tearDown(): void
    {
        $this->migrationConnection()->statement('DROP TABLE IF EXISTS '.self::OFFENDING_TABLE);
        $this->migrationConnection()->statement('DROP TABLE IF EXISTS op_inspector_probe_children');
        $this->migrationConnection()->statement('DROP TABLE IF EXISTS op_inspector_probes');

        parent::tearDown();
    }

    #[Test]
    public function the_deployed_schema_satisfies_every_mechanical_convention(): void
    {
        self::assertSame([], $this->describe($this->inspector()->inspect()));
    }

    #[Test]
    public function a_table_outside_the_six_storage_domains_is_reported(): void
    {
        // Negative test for DB-002 / DB-014 ‡ against a real schema.
        $this->migrationConnection()->statement(
            'CREATE TABLE '.self::OFFENDING_TABLE.' (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) '
            .'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs'
        );

        $violations = $this->inspector()->inspect();
        $statements = array_map(static fn (SchemaViolation $v): string => $v->statement(), $violations);

        self::assertContains('DB-002/DB-014', $statements);
        self::assertContains('DB-020', $statements, 'DB-020 ‡ — the identifier also incorporates a geography.');
    }

    #[Test]
    public function a_table_on_the_wrong_engine_is_reported(): void
    {
        // Negative test for DB-009 ‡. MyISAM is not transactional and not
        // crash-safe; DADR-02 exists because an operation and its evidential
        // record must commit together (DB-011 ‡).
        $this->migrationConnection()->statement(
            'CREATE TABLE op_inspector_probes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) '
            .'ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs'
        );

        $violations = $this->describe($this->inspector()->inspect());

        self::assertNotEmpty(array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'DB-009') && str_contains($v, 'op_inspector_probes'),
        ), implode(' | ', $violations));
    }

    #[Test]
    public function a_table_on_a_non_deterministic_collation_is_reported(): void
    {
        // Negative test for DB-010. utf8mb4_general_ci treats distinct strings as
        // equal, which would make a unique constraint mean something other than
        // what it says.
        $this->migrationConnection()->statement(
            'CREATE TABLE op_inspector_probes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) '
            .'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
        );

        $violations = $this->describe($this->inspector()->inspect());

        self::assertNotEmpty(array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'DB-010') && str_contains($v, 'op_inspector_probes'),
        ), implode(' | ', $violations));
    }

    #[Test]
    public function an_index_that_does_not_name_its_table_and_columns_is_reported(): void
    {
        // Negative test for DB-018: an index name states its table and its
        // columns, so that a duplicate index is visible by name.
        $this->migrationConnection()->statement(
            'CREATE TABLE op_inspector_probes ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            .'external_id VARBINARY(64) NOT NULL, '
            .'INDEX idx_1 (external_id)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs'
        );

        $violations = $this->describe($this->inspector()->inspect());

        self::assertNotEmpty(array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'DB-018'),
        ), implode(' | ', $violations));
    }

    #[Test]
    public function a_check_constraint_that_does_not_name_its_rule_is_reported(): void
    {
        // Negative test for DB-019 / DB-203: a constraint name states the rule it
        // enforces, so that a violation message names the rule.
        $this->migrationConnection()->statement(
            'CREATE TABLE op_inspector_probes ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            .'seats_confirmed INT NOT NULL, '
            .'CONSTRAINT op_inspector_probes_c1 CHECK (seats_confirmed >= 0)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs'
        );

        $violations = $this->describe($this->inspector()->inspect());

        self::assertNotEmpty(array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'DB-019'),
        ), implode(' | ', $violations));
    }

    #[Test]
    public function a_foreign_key_cascading_a_delete_into_evidence_is_reported(): void
    {
        // Negative test for DB-030 ‡. DADR-12 gives the reason: retention removal
        // "never deletes a row that another party is entitled to as evidence",
        // because row deletion would break the evidential hash chain. A cascade is
        // how such a deletion happens without anyone writing one.
        $this->createCascadingPair();

        $violations = $this->describe($this->inspector()->inspect());

        self::assertNotEmpty(array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'DB-030'),
        ), implode(' | ', $violations));
    }

    #[Test]
    public function a_restricting_foreign_key_into_the_same_table_is_not_reported(): void
    {
        // TC-041: the rule must produce no false positive in correct code. The one
        // foreign key the schema actually has — cfg_policy_versions into
        // cfg_policy_values — is RESTRICT, and the same shape with RESTRICT into
        // op_ is correct and must pass.
        $this->createCascadingPair('RESTRICT');

        $violations = $this->describe($this->inspector()->inspect());

        self::assertSame([], array_values(array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'DB-030'),
        )), implode(' | ', $violations));
    }

    #[Test]
    public function foreign_keys_the_server_has_been_told_to_ignore_are_reported(): void
    {
        // Negative test for DB-029: "foreign keys shall be declared and enforced
        // by the database". A key the server ignores is a key in name only, and
        // DB-012 ‡, DB-013 and DB-030 ‡ all rest on the server applying what the
        // schema declares.
        $connection = $this->applicationConnection();
        $connection->statement('SET SESSION foreign_key_checks = 0');

        try {
            $violations = $this->describe((new SchemaConventionInspector($connection))->inspect());

            self::assertNotEmpty(array_filter(
                $violations,
                static fn (string $v): bool => str_contains($v, 'DB-029'),
            ), implode(' | ', $violations));
        } finally {
            $connection->statement('SET SESSION foreign_key_checks = 1');
        }
    }

    #[Test]
    public function the_deployed_session_enforces_foreign_keys(): void
    {
        // The positive half of DB-029, stated separately so a green suite is not
        // resting on the negative test having restored the switch.
        $violations = $this->describe($this->inspector()->inspect());

        self::assertSame([], array_values(array_filter(
            $violations,
            static fn (string $v): bool => str_contains($v, 'DB-029'),
        )));
    }

    /**
     * A parent in `op_` and a child pointing at it, so that the delete rule is
     * the only thing under test.
     *
     * `op_` is one of the three domains `DB-030` ‡ protects, and the pair is
     * dropped in `tearDown()`. Creating a fixture table is not a migration —
     * `CheckConstraintEnforcementProbe` does the same thing for `OPS-024` ‡ — so
     * `DB-213`'s forward-only rule is not in play.
     */
    private function createCascadingPair(string $deleteRule = 'CASCADE'): void
    {
        $migration = $this->migrationConnection();

        $migration->statement(
            'CREATE TABLE op_inspector_probes (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY) '
            .'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs'
        );

        $migration->statement(
            'CREATE TABLE op_inspector_probe_children ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            .'op_inspector_probe_id BIGINT UNSIGNED NOT NULL, '
            .'CONSTRAINT op_inspector_probe_children_op_inspector_probe_id_foreign '
            .'FOREIGN KEY (op_inspector_probe_id) REFERENCES op_inspector_probes (id) ON DELETE '.$deleteRule
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs'
        );
    }

    /**
     * @param  list<SchemaViolation>  $violations
     * @return list<string>
     */
    private function describe(array $violations): array
    {
        return array_map(static fn (SchemaViolation $v): string => $v->describe(), $violations);
    }

    private function inspector(): InspectsSchemaConventions
    {
        return $this->app->make(InspectsSchemaConventions::class);
    }
}
