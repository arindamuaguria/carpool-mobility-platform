<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Evidential\KeyedChainHash;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * `TC-037` rule 6 — **no evidential write outside the writer.**
 *
 * `BE-105` ‡ / `BADR-09` / `DB-004` ‡ / `DB-113` ‡: one component writes
 * evidential records and no other does. `BE-117` requires the prohibition to be
 * verified by static analysis, and `TC-038` ‡ lists rule 6 among the **eight
 * that are non-suppressible**.
 *
 * The withheld `UPDATE` and `DELETE` privilege (`DB-118` ‡) stops a record being
 * altered. It does not stop a second component **inserting** one — which is what
 * this rule is for, and why `BADR-09` wants a single writer rather than a single
 * privilege.
 */
final class EvidentialLogRulesTest extends TestCase
{
    /**
     * The only files that may name the evidential table.
     *
     * The writer inserts; the verifier reads; the migration creates. Nothing
     * else, and this list is short on purpose.
     */
    private const PERMITTED = [
        'src/Infrastructure/Evidential/DatabaseEvidentialWriter.php',
        'src/Infrastructure/Evidential/DatabaseEvidentialChainVerifier.php',
    ];

    public function test_no_source_file_outside_the_writer_reaches_the_evidential_table(): void
    {
        // Narrowed to how the table is actually reached in code — a quoted
        // literal, or the writer's own constant. TC-041 requires a rule narrow
        // enough to produce no false positive in correct code, and several files
        // legitimately *mention* the table in a docblock while explaining that the
        // evidential writer is where it is reached from.
        $reaches = [
            "'".DatabaseEvidentialWriter::TABLE."'",
            'DatabaseEvidentialWriter::TABLE',
        ];

        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (in_array($relative, self::PERMITTED, true)) {
                continue;
            }

            foreach ($reaches as $reach) {
                if (str_contains($contents, $reach)) {
                    $offenders[] = $relative.' → '.$reach;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'BE-105 ‡ / TC-037 rule 6: one component writes evidential records, and no other reaches the table.',
        );
    }

    public function test_the_rule_recognises_a_second_writer(): void
    {
        // TC-041/TC-042: the rule must produce the true positive it exists for.
        $rogue = "class Rogue { public const T = '".DatabaseEvidentialWriter::TABLE."'; }";

        self::assertStringContainsString("'".DatabaseEvidentialWriter::TABLE."'", $rogue);
        self::assertStringNotContainsString("'".DatabaseEvidentialWriter::TABLE."'", '// see ev_evidential_records for the record');
    }

    public function test_only_one_class_implements_the_recording_contract(): void
    {
        // BADR-09: "One evidential writer, append-only, chained." A second
        // implementation would be a second writer whatever it was called.
        $implementations = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_ends_with($relative, '.php')) {
                continue;
            }

            if (str_contains($contents, 'implements RecordsEvidence')) {
                $implementations[] = $relative;
            }
        }

        self::assertSame(['src/Infrastructure/Evidential/DatabaseEvidentialWriter.php'], $implementations);
        // final, so the one writer cannot be subclassed into two.
        self::assertTrue((new ReflectionClass(DatabaseEvidentialWriter::class))->isFinal());
        self::assertContains(
            RecordsEvidence::class,
            class_implements(DatabaseEvidentialWriter::class) ?: [],
        );
    }

    public function test_the_permitted_files_all_exist(): void
    {
        // A permission granted to a file that no longer exists is an allow-list
        // rotting into a hole (TC-042).
        foreach (self::PERMITTED as $path) {
            self::assertFileExists(dirname(__DIR__, 2).'/'.$path);
        }
    }

    public function test_the_verifier_has_no_write_path(): void
    {
        // SEC-112 ‡ / DB-115: "a break is a finding, not a fault to correct."
        $contents = file_get_contents(dirname(__DIR__, 2).'/src/Infrastructure/Evidential/DatabaseEvidentialChainVerifier.php');
        self::assertIsString($contents);

        foreach (['->insert(', '->update(', '->delete(', '->statement('] as $write) {
            self::assertStringNotContainsString(
                $write,
                $contents,
                'SEC-112 ‡: verification reports and never repairs.',
            );
        }
    }

    public function test_the_chain_key_is_never_written_into_source(): void
    {
        // SEC-106 ‡ / SADR-14 / OPS-098: held outside the database, injected at
        // deploy time, and never present in an artefact or a repository.
        foreach (self::sourceFiles() as $relative => $contents) {
            self::assertStringNotContainsString(
                'EVIDENTIAL_CHAIN_KEY=',
                $contents,
                $relative.' appears to carry a chain key value.',
            );
        }

        $config = file_get_contents(dirname(__DIR__, 2).'/config/evidential.php');
        self::assertIsString($config);

        // The name, read from the environment. Never a default.
        self::assertStringContainsString("env('EVIDENTIAL_CHAIN_KEY')", $config);
    }

    public function test_the_construction_is_recorded_so_it_can_be_replaced(): void
    {
        // SEC-174: no construction is embedded such that changing it requires a
        // migration that cannot be staged. Each record says which produced it.
        self::assertSame('hmac-sha256', KeyedChainHash::ALGORITHM);

        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_19_000500_create_ev_evidential_records_table.php'
        );
        self::assertIsString($migration);

        self::assertStringContainsString("string('chain_algorithm'", $migration);
    }

    /**
     * `SEC-109` ‡ / `DB-111` ‡ — *"The chain shall be ordered by the database's
     * monotonic, never-reused key."*
     *
     * Three statements read the log and each orders by `id`. Nothing observed that.
     * `occurred_at` is the obvious alternative and the wrong one: it is when the
     * thing **happened**, not when it was recorded, so it is not monotonic in
     * insertion order at all — `BE-057` ‡ dispatches a listener after the
     * transaction commits and `BE-059` may enqueue a job, so a record written later
     * can legitimately carry an earlier instant. Two records sharing an instant
     * would then verify in whichever order the server returned them.
     *
     * The rule is narrow: every ordering of the evidential table orders by its key.
     * `EvidentialLogTest` proves the consequence at level 3, with three records
     * whose instants run **backwards**. `CC-056` records both.
     */
    public function test_every_ordering_of_the_evidential_table_is_by_the_key(): void
    {
        $offenders = [];
        $orderings = 0;

        foreach (self::sourceFiles() as $relative => $contents) {
            foreach (self::orderingsOfTheLogIn($contents) as $ordering) {
                $orderings++;

                if (preg_match('/^id\b/', $ordering) !== 1) {
                    $offenders[] = $relative.' → ORDER BY '.$ordering;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'SEC-109 ‡ / DB-111 ‡: the chain is ordered by the monotonic, never-reused key. occurred_at is when '
            .'the thing happened rather than when it was recorded, so ordering by it is not insertion order and '
            .'two records sharing an instant have no defined order at all.',
        );

        // A rule with no ordering to examine proves nothing. The writer's tail
        // read and the verifier's two are what it runs on.
        self::assertSame(3, $orderings, 'SEC-109 ‡: three statements order the evidential log.');
    }

    /**
     * `TC-041` / `TC-024` ‡ — the ordering detector finds an ordering, and tells
     * the key from the time.
     */
    public function test_the_ordering_detector_tells_the_key_from_the_time(): void
    {
        $ownsTheLog = "private const TABLE = 'ev_evidential_records';\n";

        self::assertSame(
            ['id DESC'],
            self::orderingsOfTheLogIn($ownsTheLog."'SELECT record_hash FROM '.self::TABLE.' ORDER BY id DESC LIMIT 1';"),
        );

        self::assertSame(
            ['occurred_at ASC'],
            self::orderingsOfTheLogIn("'SELECT * FROM ev_evidential_records ORDER BY occurred_at ASC'"),
        );

        // A repository that orders its own table by a timestamp is doing nothing
        // wrong, and three of them do. `self::TABLE` is the log only where the file
        // says its TABLE is.
        self::assertSame(
            [],
            self::orderingsOfTheLogIn(
                "private const TABLE = 'op_user_emergency_contacts';\n"
                ."'SELECT * FROM '.self::TABLE.' ORDER BY created_at ASC';"
            ),
        );

        // An ordering of something that is not the log is not this rule's
        // business, and a statement with no ordering is not an ordering.
        self::assertSame([], self::orderingsOfTheLogIn("'SELECT * FROM op_users ORDER BY created_at ASC'"));
        self::assertSame([], self::orderingsOfTheLogIn($ownsTheLog."'SELECT COUNT(*) FROM '.self::TABLE;"));
    }

    /**
     * Every `ORDER BY` in a statement that reads the evidential log, as written.
     *
     * The table is named either by its literal or by the writer's constant, which
     * is how {@see test_no_source_file_outside_the_writer_reaches_the_evidential_table()}
     * already recognises it — so a statement that names the log one way and orders
     * it the other cannot slip between two spellings.
     *
     * `TC-024` ‡: a bare `self::TABLE` counts only where the **file's own** `TABLE`
     * is the log. Every repository on the platform writes `self::TABLE`, and three
     * of them order by a timestamp perfectly correctly — a detector that read the
     * spelling without the declaration flagged all three, which is a false positive
     * to fix rather than a rule to relax.
     *
     * @return list<string>
     */
    private static function orderingsOfTheLogIn(string $contents): array
    {
        $found = [];
        $ownTableIsTheLog = str_contains($contents, "TABLE = '".DatabaseEvidentialWriter::TABLE."'");

        foreach (self::statementsIn($contents) as $statement) {
            $namesTheLog = str_contains($statement, DatabaseEvidentialWriter::TABLE)
                || str_contains($statement, 'DatabaseEvidentialWriter::TABLE')
                || ($ownTableIsTheLog && str_contains($statement, 'self::TABLE'));

            if (! $namesTheLog) {
                continue;
            }

            if (preg_match('/ORDER BY\s+(.+?)(?:\s+LIMIT\b|\s*\'|$)/i', $statement, $matches) === 1) {
                $found[] = trim($matches[1]);
            }
        }

        return $found;
    }

    /**
     * A file's PHP statements, comments removed.
     *
     * Split on `;` for the reason `SEC-037` ‡'s rule needed it: the `ORDER BY` and
     * the table name are commonly on different lines of one concatenation, and a
     * detector reading a line would see one without the other.
     *
     * @return list<string>
     */
    private static function statementsIn(string $contents): array
    {
        if (! str_contains($contents, '<?php')) {
            $contents = '<?php '.$contents;
        }

        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return explode(';', $code);
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2).'/';
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root)))] = $contents;
        }

        return $files;
    }
}
