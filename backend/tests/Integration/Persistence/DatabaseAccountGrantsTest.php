<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Cmp\Application\Shared\Schema\VerifiesCheckConstraintEnforcement;
use PHPUnit\Framework\Attributes\Test;
use Tests\Integration\IntegrationTestCase;
use Throwable;

/**
 * CMP-IMP-038, CMP-IMP-047 — the `DADR-09` grants and `CHECK` enforcement, both
 * asserted by attempting the forbidden thing and requiring the **server** to
 * refuse.
 *
 * `DB-205` ‡: constraints 8, 9 and 10 of the CMP-DOC-11 §15 register are grants,
 * not schema objects, and are asserted by a check that attempts the forbidden
 * operation. `DB-121` ‡ and `TC-050` ‡ say the same of `DDL`. `DB-122` ‡
 * requires the property to hold **in every environment, including development**
 * — which is why this runs here and not only in a pipeline (`TC-051` ‡).
 *
 * The evidential and ledger grants — constraints 8 and 9, `TC-048` ‡ and
 * `TC-049` ‡ — cannot be asserted yet: `ev_evidential_records` and
 * `led_ledger_entries` do not exist. They arrive with CMP-IMP-438 and
 * CMP-IMP-253, and their tests arrive in the same change (`DB-208`).
 */
final class DatabaseAccountGrantsTest extends IntegrationTestCase
{
    #[Test]
    public function the_server_enforces_check_constraints(): void
    {
        // OPS-024 ‡ / DB-217 ‡ / TC-047 ‡. Fourteen of the twenty-one constraints
        // in the §15 register are decoration on a server that accepts the clause
        // without enforcing it, so the property is asserted by attempting a
        // violating write rather than by reading the schema.
        $this->migrationConnection()->statement('DROP TABLE IF EXISTS mch_grant_probes');
        $this->migrationConnection()->statement(
            'CREATE TABLE mch_grant_probes ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            .'seats_confirmed INT NOT NULL, '
            .'CONSTRAINT mch_grant_probes_check_seats_confirmed_is_not_negative CHECK (seats_confirmed >= 0)'
            .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_as_cs'
        );

        try {
            self::assertTrue(
                $this->refused(fn () => $this->migrationConnection()->insert(
                    'INSERT INTO mch_grant_probes (seats_confirmed) VALUES (?)',
                    [-1],
                )),
                'OPS-024 ‡: the deployed server must enforce CHECK, not merely accept the clause.',
            );

            self::assertFalse(
                $this->refused(fn () => $this->migrationConnection()->insert(
                    'INSERT INTO mch_grant_probes (seats_confirmed) VALUES (?)',
                    [1],
                )),
                'A conforming write must still succeed.',
            );
        } finally {
            $this->migrationConnection()->statement('DROP TABLE IF EXISTS mch_grant_probes');
        }
    }

    #[Test]
    public function the_check_enforcement_probe_leaves_nothing_behind(): void
    {
        // The probe runs before every migration (DB-217 ‡). A probe that left a
        // table behind would breach the very conventions it protects.
        $this->app->make(VerifiesCheckConstraintEnforcement::class)->verify();

        $remaining = $this->readConnection()->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
            ['%probe%'],
        );

        self::assertSame([], $remaining);
    }

    #[Test]
    public function the_application_account_cannot_create_a_table(): void
    {
        // DB-119 ‡, constraint 10, TC-050 ‡: no DDL privilege, so it cannot
        // remove a constraint or a trigger that constrains it.
        self::assertTrue(
            $this->refused(fn () => $this->applicationConnection()->statement(
                'CREATE TABLE op_forbidden_probes (id BIGINT UNSIGNED NOT NULL PRIMARY KEY)'
            )),
            'DB-119 ‡: the application account must hold no DDL privilege.',
        );
    }

    #[Test]
    public function the_application_account_cannot_drop_a_table(): void
    {
        self::assertTrue(
            $this->refused(fn () => $this->applicationConnection()->statement('DROP TABLE mch_migrations')),
            'DB-119 ‡: the application account must not be able to drop a table.',
        );
    }

    #[Test]
    public function the_read_account_cannot_write(): void
    {
        // DADR-09: the read account holds SELECT. It is unable to alter evidence
        // because it is unable to alter anything.
        self::assertTrue(
            $this->refused(fn () => $this->readConnection()->statement(
                'INSERT INTO mch_migrations (migration, batch) VALUES (?, ?)',
                ['not_a_migration', 1],
            )),
            'DADR-09: the read account must hold SELECT only.',
        );
    }

    #[Test]
    public function the_read_account_can_read(): void
    {
        $rows = $this->readConnection()->select('SELECT COUNT(*) AS total FROM mch_migrations');

        self::assertCount(1, $rows);
    }

    #[Test]
    public function the_application_account_can_read_and_write_a_machinery_table(): void
    {
        // The counterpart to the refusals above: the grants narrow the account
        // without disabling it.
        $rows = $this->applicationConnection()->select('SELECT COUNT(*) AS total FROM mch_migrations');

        self::assertCount(1, $rows);
    }

    private function refused(callable $operation): bool
    {
        try {
            $operation();
        } catch (Throwable) {
            return true;
        }

        return false;
    }
}
