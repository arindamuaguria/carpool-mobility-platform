<?php

declare(strict_types=1);

namespace Tests\Domain\Persistence;

use Cmp\Infrastructure\Persistence\Grants\GrantPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Domain\DomainTestCase;

/**
 * CMP-IMP-047 — the three `DADR-09` accounts and their grants.
 *
 * Constraints 8, 9 and 10 of the CMP-DOC-11 §15 register are grants, not schema
 * objects (`DB-205` ‡). This suite proves the **plan** is right; the integration
 * suite proves the server actually refuses (`DB-121` ‡, `TC-048`–`TC-050` ‡).
 */
final class GrantPlanTest extends DomainTestCase
{
    private const TABLES = [
        'op_rides',
        'proj_ride_summaries',
        'mch_idempotency_registries',
        'ev_evidential_records',
        'led_ledger_entries',
        'cfg_policy_values',
    ];

    #[Test]
    public function the_application_may_write_operational_projection_and_machinery_tables(): void
    {
        $grants = $this->plan()->applicationGrants('cmp_app', self::TABLES);

        foreach (['op_rides', 'proj_ride_summaries', 'mch_idempotency_registries'] as $table) {
            self::assertContains(
                "GRANT SELECT, INSERT, UPDATE, DELETE ON `cmp`.`{$table}` TO 'cmp_app'@'%'",
                $grants,
            );
        }
    }

    #[Test]
    public function the_application_may_only_append_to_the_evidential_and_ledger_domains(): void
    {
        // DB-118 ‡ evidential, DB-094 ledger: SELECT and INSERT, never UPDATE,
        // never DELETE. This is the grant that makes BE-110 a property of the
        // credential rather than of the code.
        $grants = $this->plan()->applicationGrants('cmp_app', self::TABLES);

        foreach (['ev_evidential_records', 'led_ledger_entries'] as $table) {
            self::assertContains(
                "GRANT SELECT, INSERT ON `cmp`.`{$table}` TO 'cmp_app'@'%'",
                $grants,
            );
        }

        foreach ($grants as $grant) {
            if (str_contains($grant, 'ev_') || str_contains($grant, 'led_')) {
                self::assertStringNotContainsString('UPDATE', $grant);
                self::assertStringNotContainsString('DELETE', $grant);
            }
        }
    }

    #[Test]
    public function the_application_holds_no_ddl_on_any_domain(): void
    {
        // DB-119 ‡: it cannot remove a constraint or a trigger that constrains it.
        foreach ($this->plan()->applicationGrants('cmp_app', self::TABLES) as $grant) {
            foreach (['CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES', 'TRIGGER', 'GRANT OPTION'] as $privilege) {
                self::assertStringNotContainsString($privilege.' ON', $grant);
                self::assertStringNotContainsString($privilege.',', $grant);
            }
        }
    }

    #[Test]
    public function a_table_declaring_no_storage_domain_receives_no_grant(): void
    {
        // DB-002 and DB-014 make domain membership readable from the name.
        // Defaulting an undeclared table to read-write would quietly grant more
        // than DADR-09 allows, so it is granted nothing at all.
        self::assertSame([], $this->plan()->applicationGrants('cmp_app', ['users', 'sessions']));
    }

    #[Test]
    public function the_migration_account_is_the_only_one_holding_ddl(): void
    {
        // DB-215.
        $grants = $this->plan()->migrationGrants('cmp_migration');

        self::assertCount(1, $grants);
        self::assertStringContainsString('CREATE, ALTER, DROP, INDEX, REFERENCES', $grants[0]);
        self::assertStringContainsString("ON `cmp`.* TO 'cmp_migration'@'%'", $grants[0]);
    }

    #[Test]
    public function the_read_account_can_alter_nothing(): void
    {
        $grants = $this->plan()->readGrants('cmp_read');

        self::assertSame(["GRANT SELECT ON `cmp`.* TO 'cmp_read'@'%'"], $grants);
    }

    #[Test]
    public function a_plan_starts_by_revoking_everything(): void
    {
        // A grant added to whatever was there before is not a plan. Provisioning
        // narrows an account; it must never widen one by accident.
        self::assertSame(
            ["REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'cmp_app'@'%'"],
            $this->plan()->revokeAll('cmp_app'),
        );
    }

    #[Test]
    public function a_schema_name_that_is_not_a_bare_identifier_is_refused(): void
    {
        // DB-038 ‡ forbids concatenating a value into a statement. GRANT cannot
        // bind its identifiers, so the identifier is constrained instead.
        $this->expectException(InvalidArgumentException::class);

        new GrantPlan('cmp`; DROP DATABASE cmp; --');
    }

    #[Test]
    public function a_table_name_that_is_not_a_bare_identifier_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->plan()->applicationGrants('cmp_app', ['op_rides`; DROP TABLE op_rides; --']);
    }

    private function plan(): GrantPlan
    {
        return new GrantPlan('cmp');
    }
}
