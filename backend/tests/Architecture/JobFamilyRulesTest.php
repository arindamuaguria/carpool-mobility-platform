<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Application\Shared\Work\JobFamily;
use PHPUnit\Framework\TestCase;

/**
 * CMP-IMP-027 — the structural half of the job families.
 *
 * `BE-131` requires the seven families **and no undeclared eighth**. The way an
 * eighth appears in practice is not by editing the enum: it is a queue name
 * written as a string somewhere, or a framework default of `default` that
 * anything forgetting to name a family quietly lands on. Both are checked here.
 *
 * `BE-142` puts job storage in the relational database at launch; the
 * environment inventory is where `BE-015` and `TECH-DEC-005` say that is
 * recorded, so it is asserted there rather than against a runtime value the test
 * environment deliberately overrides.
 */
final class JobFamilyRulesTest extends TestCase
{
    public function test_the_configuration_names_no_queue_the_enum_does_not_declare(): void
    {
        // BE-131. A literal queue name in configuration is how an eighth family
        // gets in without anyone deciding to add one.
        $config = file_get_contents(self::basePath('config/queue.php'));
        self::assertIsString($config);

        self::assertStringNotContainsString(
            "'default'",
            self::afterConnections($config),
            "BE-131: a queue named 'default' would be an undeclared eighth family.",
        );

        self::assertStringContainsString(
            'JobFamily::',
            $config,
            'The configuration binds the families declared in JobFamily rather than naming queues itself.',
        );
    }

    public function test_no_source_file_writes_a_family_name_as_a_literal(): void
    {
        // A literal 'safety' passed as a queue name would bypass the enum, and
        // BE-132 ‡ turns on the enum's ordering being the only ordering.
        //
        // **"As a queue name"** is the whole of it, and the detector now says
        // so. API-163 ‡ puts the safety surface under the path prefix `safety`,
        // so SafetySurface::PREFIX is the literal 'safety' meaning a URL
        // segment — one word, two unrelated concepts. Flagging it was a false
        // positive, and TC-024 ‡ requires the detector fixed rather than the
        // constant renamed to something API-163 ‡ does not permit.
        //
        // So a file is examined only where it deals with queued work at all: a
        // file that never mentions a queue cannot be naming one.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if ($relative === 'src/Application/Shared/Work/JobFamily.php') {
                continue;
            }

            if (! self::dealsWithQueuedWork($contents)) {
                continue;
            }

            foreach (JobFamily::cases() as $family) {
                if (str_contains($contents, "'".$family->value."'")) {
                    $offenders[] = $relative.' → '.$family->value;
                }
            }
        }

        self::assertSame([], $offenders, 'BE-131: a family is named by the enum, never by a string literal.');
    }

    /**
     * `TC-041` / `TC-024` ‡ — the narrowed detector still fires, and no
     * longer fires on a path prefix that happens to be the same word.
     */
    public function test_the_family_literal_detector_recognises_a_queue_name(): void
    {
        self::assertTrue(
            self::dealsWithQueuedWork('$this->onQueue("safety");'),
            'A file calling onQueue() is naming a queue.',
        );

        self::assertTrue(self::dealsWithQueuedWork('final class X implements ShouldQueue {}'));

        self::assertFalse(
            self::dealsWithQueuedWork('final class SafetySurface { public const PREFIX = "safety"; }'),
            "API-163 ‡'s path prefix is not a queue name, and one word is not two concepts.",
        );
    }

    /**
     * Whether a file has anything to do with queued work.
     *
     * Narrow on purpose. `BE-131`’s subject is a family **named as a queue**,
     * and a file mentioning no queue machinery has not named one. A job, a
     * dispatcher and the queue configuration each say so in their own text.
     */
    private static function dealsWithQueuedWork(string $contents): bool
    {
        foreach (['onQueue', 'ShouldQueue', 'JobFamily', 'Queueable', 'PlatformJob', 'connections'] as $signal) {
            if (str_contains($contents, $signal)) {
                return true;
            }
        }

        return false;
    }

    public function test_the_environment_inventory_records_the_database_queue(): void
    {
        // BE-142 / BADR-07. TECH-DEC-005 makes .env.example the inventory, and
        // BE-015 keeps the value out of source.
        $inventory = file_get_contents(self::basePath('.env.example'));
        self::assertIsString($inventory);

        self::assertStringContainsString('QUEUE_CONNECTION=database', $inventory);
    }

    public function test_the_failed_store_is_not_a_discarding_driver(): void
    {
        // DB-147 / BE-137: an exhausted job moves to a state visible to
        // operations. BE-138 ‡ needs the row to still be there.
        $config = file_get_contents(self::basePath('config/queue.php'));
        self::assertIsString($config);

        self::assertStringContainsString("'driver' => 'database-uuids'", $config);
        self::assertStringNotContainsString("'driver' => 'null'", $config);
    }

    /**
     * The part of the configuration that declares connections and queues, so
     * that the word `default` in the top-level `'default' =>` key does not fire.
     */
    private static function afterConnections(string $config): string
    {
        $position = strpos($config, "'connections'");

        return $position === false ? $config : substr($config, $position);
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function sourceFiles(): array
    {
        $root = self::basePath('src');
        $files = [];

        /** @var iterable<\SplFileInfo> $iterator */
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(self::basePath(''))));
            $files[$relative] = $contents;
        }

        return $files;
    }

    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
