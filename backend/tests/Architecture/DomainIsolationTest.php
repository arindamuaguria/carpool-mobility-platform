<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `TC-029` ‡ / `TC-058` ‡ — a domain test runs without a database, a framework
 * or a network. `BE-002` / `BE-040` — the Domain namespace references no
 * framework type and is unit-testable without one.
 *
 * Deptrac covers src/. It does not cover tests/, and a domain test that boots
 * the framework would satisfy deptrac while breaking `TC-029` ‡ outright. This
 * test closes that gap.
 */
final class DomainIsolationTest extends TestCase
{
    /**
     * Namespace roots that mean a framework, a database driver or a network
     * client has been reached for.
     */
    private const FORBIDDEN_ROOTS = [
        'Illuminate',
        'Laravel',
        'Symfony',
        'Filament',
        'Livewire',
        'Carbon',
        'Doctrine',
        'GuzzleHttp',
        'Faker',
    ];

    /**
     * Framework-aware base classes a domain test must not extend.
     */
    private const FORBIDDEN_BASES = [
        'Tests\TestCase',
        'Illuminate\Foundation\Testing\TestCase',
    ];

    #[Test]
    public function the_domain_namespace_imports_no_framework_type(): void
    {
        foreach (self::phpFilesUnder('src/Domain') as $file => $contents) {
            foreach (self::FORBIDDEN_ROOTS as $root) {
                self::assertFalse(
                    self::references($contents, $root),
                    "BE-002: {$file} references {$root}. The Domain namespace references no framework type.",
                );
            }
        }
    }

    #[Test]
    public function domain_tests_import_no_framework_type(): void
    {
        foreach (self::phpFilesUnder('tests/Domain') as $file => $contents) {
            foreach (self::FORBIDDEN_ROOTS as $root) {
                self::assertFalse(
                    self::references($contents, $root),
                    "TC-029 ‡: {$file} references {$root}. A domain test runs without a framework.",
                );
            }
        }
    }

    #[Test]
    public function domain_tests_do_not_extend_a_framework_aware_case(): void
    {
        foreach (self::phpFilesUnder('tests/Domain') as $file => $contents) {
            foreach (self::FORBIDDEN_BASES as $base) {
                self::assertStringNotContainsString(
                    $base,
                    $contents,
                    "TC-029 ‡: {$file} reaches for {$base}, which boots the framework.",
                );
            }
        }
    }

    #[Test]
    public function the_domain_test_base_does_not_boot_the_framework(): void
    {
        $contents = file_get_contents(self::basePath('tests/Domain/DomainTestCase.php'));
        self::assertIsString($contents);

        self::assertStringContainsString('use PHPUnit\Framework\TestCase;', $contents);
    }

    #[Test]
    public function the_detector_recognises_a_framework_reference(): void
    {
        // TC-041/TC-042: the rule must produce the true positive it exists for.
        $separator = chr(92);

        self::assertTrue(self::references('use Illuminate'.$separator.'Support'.$separator.'Collection;', 'Illuminate'));
        self::assertTrue(self::references('new '.$separator.'Illuminate'.$separator.'Support'.$separator.'Collection();', 'Illuminate'));
        self::assertFalse(self::references('final class IlluminateLike {}', 'Illuminate'));
    }

    /**
     * A `use` statement or a fully qualified reference to the given namespace root.
     */
    private static function references(string $contents, string $root): bool
    {
        $separator = chr(92);

        return str_contains($contents, 'use '.$root.$separator)
            || str_contains($contents, $separator.$root.$separator);
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function phpFilesUnder(string $relativeDirectory): array
    {
        $root = self::basePath($relativeDirectory);
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            $files[$relativeDirectory.'/'.$file->getFilename()] = $contents;
        }

        return $files;
    }

    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
