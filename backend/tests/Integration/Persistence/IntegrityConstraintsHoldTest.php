<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Illuminate\Database\Connection;
use Tests\Integration\IntegrationTestCase;
use Tests\IntegrityConstraints;

/**
 * `CMP-IMP-050` — the register's claims, checked against the deployed schema.
 *
 * Level 3 (`TC-030` ‡). The register says which constraints hold; this is what
 * stops it saying so wrongly. A register that claimed an enforcement the schema
 * did not have would be worse than no register, because `DB-207` ‡'s violation
 * tests would then be resting on it.
 *
 * The individual violation attempts `DB-207` ‡ requires live with the constraint
 * they attack — `EvidentialLogTest` attempts the update the trigger refuses,
 * `IdempotencyRegistryTest` attempts the duplicate the unique refuses — because
 * each belongs beside the reasoning that motivates it. What is checked here is
 * that the object each register entry names is really in the schema.
 */
final class IntegrityConstraintsHoldTest extends IntegrationTestCase
{
    public function test_every_named_constraint_object_exists_in_the_deployed_schema(): void
    {
        $missing = [];

        foreach (IntegrityConstraints::all() as $number => $entry) {
            if ($entry['object'] === null) {
                continue;
            }

            foreach (self::objectsIn($entry) as $object) {
                if (! $this->schemaHolds($entry['kind'], $object)) {
                    $missing[] = $number.': '.$object;
                }
            }
        }

        self::assertSame([], $missing, 'The register names a constraint the deployed schema does not have.');
    }

    public function test_no_absent_or_withheld_constraint_has_quietly_appeared(): void
    {
        // The other direction, and the one that matters for constraint 18: a
        // ratings table appearing would mean a withheld area had been built.
        // CLAUDE.md §5 and ADM-187/ADM-191 forbid it even disabled or flagged.
        /** @var list<object{TABLE_NAME: string}> $rows */
        $rows = $this->readConnection()->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        );

        $tables = array_map(static fn (object $row): string => $row->TABLE_NAME, $rows);

        foreach (['op_ratings', 'op_rating', 'led_ratings'] as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $tables,
                'CC-025 / CMP-DOC-04 §9.2: ratings is a withheld area and constraint 18 must not become enforced.',
            );
        }
    }

    public function test_the_evidential_triggers_are_both_present(): void
    {
        // Constraint 11, and the one register entry whose object is not visible
        // in TABLE_CONSTRAINTS at all. DB-120 ‡ wants both; a schema holding only
        // the update trigger would pass a laxer check.
        /** @var list<object{TRIGGER_NAME: string}> $rows */
        $rows = $this->triggerReadingConnection()->select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
        );

        $triggers = array_map(static fn (object $row): string => $row->TRIGGER_NAME, $rows);

        self::assertContains('ev_evidential_records_refuse_update', $triggers);
        self::assertContains('ev_evidential_records_refuse_delete', $triggers);
    }

    public function test_neither_the_application_nor_the_read_account_can_even_see_the_triggers(): void
    {
        // Not a defect, and worth stating because it is why the check above uses
        // a different account. MySQL shows information_schema.TRIGGERS only to a
        // connection holding TRIGGER on the table, and DADR-09 gives that to the
        // migration account alone.
        //
        // DB-119 is the consequence that matters: the application cannot drop the
        // trigger that constrains it, and cannot so much as learn its name through
        // the catalogue. SEC-110 ‡ keeps the withheld privilege as the primary
        // defence; this is the second layer being out of reach as well as out of
        // permission.
        foreach ([$this->applicationConnection(), $this->readConnection()] as $connection) {
            self::assertSame(
                [],
                $connection->select(
                    'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()'
                ),
            );
        }
    }

    /**
     * `DADR-09`: the migration account is the only one holding `TRIGGER`, and
     * MySQL discloses `information_schema.TRIGGERS` only to a connection that
     * does.
     */
    private function triggerReadingConnection(): Connection
    {
        return $this->migrationConnection();
    }

    /**
     * @param  array{object: ?string}  $entry
     * @return list<string>
     */
    private static function objectsIn(array $entry): array
    {
        $object = $entry['object'];

        if ($object === null) {
            return [];
        }

        // A NOT NULL entry names table.column,column; the others name one object.
        if (str_contains($object, '.')) {
            [$table, $columns] = explode('.', $object, 2);

            return array_map(
                static fn (string $column): string => $table.'.'.$column,
                explode(',', $columns),
            );
        }

        return explode(',', $object);
    }

    private function schemaHolds(string $kind, string $object): bool
    {
        if ($kind === 'not_null') {
            [$table, $column] = explode('.', $object, 2);

            $rows = $this->readConnection()->select(
                'SELECT IS_NULLABLE FROM information_schema.COLUMNS'
                .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            );

            return $rows !== [] && (string) $rows[0]->IS_NULLABLE === 'NO';
        }

        if ($kind === 'trigger') {
            $rows = $this->triggerReadingConnection()->select(
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS'
                .' WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                [$object],
            );

            return $rows !== [];
        }

        $rows = $this->readConnection()->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$object],
        );

        return $rows !== [];
    }
}
