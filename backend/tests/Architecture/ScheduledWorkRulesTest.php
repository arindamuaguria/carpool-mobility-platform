<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * CMP-IMP-029 (partial) — scheduled work is declared in one place, and nothing
 * is scheduled yet.
 *
 * `OPS-039` ‡ / `ARCH-140`: exactly one scheduler instance is active. That is a
 * deployment property, but *what* it runs is a code property, and one
 * declaration point is what makes it reviewable.
 *
 * `BE-148`: frequency is configuration, not code. Until the typed, versioned
 * policy store exists (`BADR-12`, `CMP-IMP-031`), a frequency written in code
 * would be the thing `BE-148` forbids, waiting to be found and removed.
 */
final class ScheduledWorkRulesTest extends TestCase
{
    private const DECLARATION = 'src/Infrastructure/Laravel/ScheduledWork.php';

    /**
     * Scheduler calls that declare work or a frequency.
     */
    private const SCHEDULING_CALLS = [
        '->call(',
        '->job(',
        '->command(',
        '->everyMinute(',
        '->everyFiveMinutes(',
        '->hourly(',
        '->daily(',
        '->dailyAt(',
        '->weekly(',
        '->monthly(',
        '->cron(',
    ];

    public function test_scheduled_work_is_declared_in_exactly_one_place(): void
    {
        // OPS-039 ‡: one active scheduler, and one place that says what it runs.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if ($relative === self::DECLARATION) {
                continue;
            }

            foreach (self::SCHEDULING_CALLS as $call) {
                if (str_contains($contents, 'Schedule') && str_contains($contents, $call)) {
                    $offenders[] = $relative.' → '.$call;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function test_no_frequency_is_written_in_code(): void
    {
        // BE-148. The declaration point is currently empty, so this holds
        // trivially — and it will keep holding when the first entry is added,
        // which is when it stops being trivial.
        $declaration = file_get_contents(self::basePath(self::DECLARATION));
        self::assertIsString($declaration);

        $body = self::methodBody($declaration);

        foreach (self::SCHEDULING_CALLS as $call) {
            self::assertStringNotContainsString(
                $call,
                $body,
                'BE-148: scheduled work frequency is configuration, not code. '
                .'The policy store is CMP-IMP-031 and does not exist yet.',
            );
        }
    }

    public function test_the_routes_file_declares_no_console_closure(): void
    {
        // BE-005 puts an adapter in Cmp\Interface\Console, where it has a class
        // to hold its contract and a place a test can reach it.
        $routes = file_get_contents(self::basePath('routes/console.php'));
        self::assertIsString($routes);

        self::assertStringNotContainsString('Artisan::command(', $routes);
    }

    public function test_the_blocked_entries_are_named_with_their_blockers(): void
    {
        // A declaration point that is empty for no stated reason reads as work
        // nobody has got to. Each of the four CMP-IMP-029 names is blocked, and
        // the file says which blocker applies to which.
        $declaration = file_get_contents(self::basePath(self::DECLARATION));
        self::assertIsString($declaration);

        foreach (['BE-143', 'BE-145', 'BE-146', 'BE-147', 'BAD-DEC-021', 'CMP-IMP-448', 'OPS-039'] as $reference) {
            self::assertStringContainsString($reference, $declaration);
        }
    }

    /**
     * The body of `declare()`, so that the references in the docblock — which
     * name the calls a future entry will use — do not fire the rule above.
     */
    private static function methodBody(string $contents): string
    {
        $position = strpos($contents, 'public static function declare(');

        return $position === false ? $contents : substr($contents, $position);
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function sourceFiles(): array
    {
        $root = self::basePath('');
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

    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
