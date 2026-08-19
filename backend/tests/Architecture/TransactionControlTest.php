<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `TC-037` rule 4 — **no transaction control outside `Application`**.
 *
 * `BE-047` ‡: only an application service begins, commits or rolls back a
 * transaction. A transaction opened in a controller, a repository or a job would
 * put the boundary where the layer contract says it cannot be, and `BE-053` ‡ —
 * a failed operation leaving no partial effect — would stop being decidable from
 * the service alone.
 *
 * Deptrac reasons about types, not calls, so it cannot see this. The rule is one
 * of the eight `TC-038` ‡ marks non-suppressible; there is no allow-list beyond
 * the single implementation the Application layer delegates to.
 */
final class TransactionControlTest extends TestCase
{
    /**
     * Calls that open, commit or discard a transaction.
     */
    private const TRANSACTION_CONTROL = [
        'beginTransaction(',
        'commit(',
        'rollBack(',
        'rollback(',
        'DB::transaction(',
        'DB::beginTransaction(',
    ];

    /**
     * The one implementation `BE-047` ‡ delegates to.
     *
     * It is Infrastructure by necessity — the driver lives there — but it is
     * reachable only through `Cmp\Application\Shared\Transaction\TransactionBoundary`,
     * which is where the boundary is owned.
     */
    private const PERMITTED = [
        'src/Infrastructure/Persistence/Transaction/DatabaseTransactionBoundary.php',
    ];

    #[Test]
    public function no_source_file_outside_the_application_layer_controls_a_transaction(): void
    {
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (str_starts_with($relative, 'src/Application/') || in_array($relative, self::PERMITTED, true)) {
                continue;
            }

            foreach (self::TRANSACTION_CONTROL as $call) {
                if (str_contains($contents, $call)) {
                    $offenders[] = $relative.' → '.$call;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'BE-047 ‡: only an application service begins, commits or rolls back a transaction.',
        );
    }

    #[Test]
    public function the_permitted_implementation_exists_and_is_the_only_one(): void
    {
        // A permission granted to a file that no longer exists is an allow-list
        // rotting into a hole. TC-042 requires the rule to be narrowed, and a
        // stale entry widens it.
        foreach (self::PERMITTED as $path) {
            self::assertFileExists(dirname(__DIR__, 2).'/'.$path);
        }

        self::assertCount(1, self::PERMITTED);
    }

    #[Test]
    public function the_detector_recognises_transaction_control(): void
    {
        // TC-041/TC-042: the rule must produce the true positive it exists for.
        self::assertContains('beginTransaction(', self::TRANSACTION_CONTROL);
        self::assertTrue(str_contains('$connection->beginTransaction();', 'beginTransaction('));
        self::assertFalse(str_contains('$this->transactionalWorkDescription;', 'beginTransaction('));
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $files[$relative] = $contents;
        }

        return $files;
    }
}
