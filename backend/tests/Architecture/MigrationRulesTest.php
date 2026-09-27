<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Infrastructure\Persistence\Schema\DestructiveMigrationGuard;
use PHPUnit\Framework\TestCase;

/**
 * `DB-219` ‡ — *"No migration shall rewrite an existing evidential or ledger
 * record."*
 *
 * ## Why this needed a rule of its own
 *
 * Everything else that protects the evidential log protects it from the
 * **application**. `DB-118` ‡ withholds `UPDATE` and `DELETE` from the application
 * account, `DB-108` ‡ puts a trigger in the way, and both are tested. A migration
 * is neither: `DB-215` runs it under the **migration** account, which is the one
 * account holding `UPDATE`, `DELETE` and `DDL` on every table in the schema.
 *
 * So the one path that can rewrite an evidential record is the one path no grant
 * and no trigger stops, and until this rule existed the guarantee rested on
 * nobody writing such a migration. `DB-219` ‡ was cited in three migration
 * docblocks — a statement of intent in the files that would be breaking it —
 * and in no test. `CC-056` records it.
 *
 * `DB-125` ‡ is the general form: the evidential log is append-only. `DB-093` says
 * the same of the ledger. `OPS-034` is where it matters operationally — a rollback
 * does not roll back a migration, so a migration that rewrote the log would leave
 * nothing to roll back to.
 *
 * ## What the rule forbids, and what it deliberately does not
 *
 * The four statements that change or remove what a row says: `UPDATE`, `DELETE`,
 * `REPLACE`, `TRUNCATE`, and the query-builder spellings of them. An **`INSERT`**
 * into an evidential table would be fabricated evidence and is forbidden too — by
 * `DB-221`, which limits seed data to policy configuration and reference values —
 * and it is not asserted here, because a rule should assert the statement it names.
 *
 * Dropping a column, a table or a constraint is `DB-218` ‡'s, enforced by
 * {@see DestructiveMigrationGuard} and its
 * recorded-approval register.
 */
final class MigrationRulesTest extends TestCase
{
    use ReadsMethods;

    /**
     * The two domains `DB-219` ‡ names, as `DB-002` spells them.
     *
     * `led_` has no table yet — `T5`'s monetary precision is unresolved, so no
     * money column exists and `DB-093`'s ledger is unbuilt. It is named anyway:
     * the first ledger migration should meet a rule that was already there, which
     * is the whole argument for writing this one now.
     *
     * @var list<string>
     */
    private const PROTECTED_DOMAINS = ['ev_', 'led_'];

    /**
     * Ways of changing or removing what a stored row says.
     *
     * @var list<string>
     */
    private const REWRITES = ['UPDATE', 'DELETE', 'REPLACE', 'TRUNCATE'];

    public function test_no_migration_rewrites_an_evidential_or_ledger_record(): void
    {
        $offenders = [];

        foreach (self::migrations() as $relative => $contents) {
            foreach (self::rewritesIn(self::codeOf($contents)) as $statement) {
                $offenders[] = $relative.' → '.$statement;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'DB-219 ‡: no migration rewrites an existing evidential or ledger record. A migration runs under the '
            .'migration account (DB-215), which is the one account holding UPDATE and DELETE on these tables — so '
            .'DB-118 ‡ grants and the DB-108 ‡ trigger, which stop the application, do not stop this.',
        );
    }

    /**
     * `TC-024` ‡ — the rule ran on the migrations, and on the one that matters
     * most.
     *
     * A rule over a directory proves nothing if the directory reader is wrong, and
     * `CC-053` and `CC-054` are two entries about exactly that. The evidential
     * migration is named because it is the file whose tables this rule protects: if
     * the scan stops seeing that one, the rule has stopped meaning anything.
     */
    public function test_the_scan_reads_the_migrations_that_exist(): void
    {
        $migrations = self::migrations();

        self::assertNotSame([], $migrations, 'The scan found no migration at all, so it proved nothing.');

        self::assertArrayHasKey(
            'database/migrations/2026_08_19_000500_create_ev_evidential_records_table.php',
            $migrations,
        );

        // And the reader is looking at migrations rather than at whatever else
        // lives beside them: DESTRUCTIVE-APPROVALS.md is not PHP.
        foreach (array_keys($migrations) as $relative) {
            self::assertStringEndsWith('.php', $relative);
        }
    }

    /**
     * `TC-041` / `TC-024` ‡ — the detector fires on each forbidden shape, and not
     * on the prose that forbids it nor on the migration that creates the table.
     */
    public function test_the_detector_recognises_a_rewrite_of_a_protected_table(): void
    {
        self::assertSame(
            ['UPDATE ev_evidential_records'],
            self::rewritesIn(self::codeOf("\$this->connection->update('UPDATE ev_evidential_records SET reason = ?');")),
        );

        self::assertSame(
            ['DELETE ev_evidential_records'],
            self::rewritesIn(self::codeOf("DB::statement('DELETE FROM ev_evidential_records WHERE id < 100');")),
        );

        self::assertSame(
            ['TRUNCATE led_entries'],
            self::rewritesIn(self::codeOf("DB::statement('TRUNCATE TABLE led_entries');")),
        );

        // The query-builder spelling, which carries no SQL keyword at all until it
        // is executed — the shape somebody reaches for precisely because it does
        // not look like a statement.
        self::assertSame(
            ['UPDATE ev_evidential_records'],
            self::rewritesIn(self::codeOf("DB::table('ev_evidential_records')->update(['reason' => null]);")),
        );

        self::assertSame(
            ['DELETE ev_evidential_records'],
            self::rewritesIn(self::codeOf("\$connection->table('ev_evidential_records')->delete();")),
        );

        // Creating the table is not rewriting a record, and neither is writing
        // about the prohibition — comments are gone before the detector sees a
        // file, the correction `TC-037` ‡ rule 11 needed.
        self::assertSame([], self::rewritesIn(self::codeOf("Schema::create('ev_evidential_records', function (Blueprint \$table) {")));
        self::assertSame(
            [],
            self::rewritesIn(self::codeOf('<?php // No migration may UPDATE ev_evidential_records — DB-219 ‡.')),
        );

        // An operational table is not a protected one. DB-219 ‡ names two domains,
        // and a migration correcting op_ data is an ordinary migration.
        self::assertSame([], self::rewritesIn(self::codeOf("DB::table('op_users')->update(['account_state' => 'ACTIVE']);")));
    }

    /**
     * Every rewrite of a protected table in a file, named as *verb table*.
     *
     * Two shapes, because there are two ways to write one. A statement names the
     * verb and the table in the same string; a builder chain names the table first
     * and the verb after, and carries no SQL keyword at all.
     *
     * @return list<string>
     */
    private static function rewritesIn(string $code): array
    {
        $found = [];

        foreach (self::PROTECTED_DOMAINS as $domain) {
            $table = preg_quote($domain, '/').'\w+';

            foreach (self::REWRITES as $verb) {
                // UPDATE ev_x …, DELETE FROM ev_x …, TRUNCATE TABLE led_x …
                if (preg_match('/\b'.$verb.'\b(?:\s+(?:FROM|TABLE|INTO))?\s+('.$table.')/i', $code, $matches) === 1) {
                    $found[] = $verb.' '.$matches[1];

                    continue;
                }

                // ->table('ev_x')->update(…) / ->delete(…)
                if (preg_match('/table\(\s*[\'"]('.$table.')[\'"]\s*\)\s*->\s*'.strtolower($verb).'\s*\(/i', $code, $matches) === 1) {
                    $found[] = $verb.' '.$matches[1];
                }
            }
        }

        return $found;
    }

    /**
     * Every migration, keyed by its path from the backend root.
     *
     * @return array<string, string>
     */
    private static function migrations(): array
    {
        $root = dirname(__DIR__, 2).'/';
        $directory = $root.'database/migrations';
        $files = [];

        $names = scandir($directory);

        self::assertIsArray($names);

        foreach ($names as $name) {
            if (! str_ends_with($name, '.php')) {
                continue;
            }

            $contents = file_get_contents($directory.'/'.$name);

            self::assertIsString($contents);

            $files['database/migrations/'.$name] = $contents;
        }

        return $files;
    }
}
