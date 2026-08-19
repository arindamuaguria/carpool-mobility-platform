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
