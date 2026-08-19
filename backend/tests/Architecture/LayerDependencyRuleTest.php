<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * CMP-IMP-022 — layer dependency direction enforced by static analysis.
 *
 * Realises `BE-002` (the Domain namespace references no framework type),
 * `BE-003` (dependencies point inward only), `BE-014` (verified by static
 * analysis in the build), `BADR-18` and `TC-037` rules 1, 2, 3 and 7.
 *
 * `TC-037` ‡ requires the rule to fail the build, so this class asserts both
 * directions: that the source satisfies the rule, and that the rule reports a
 * violation when one is present. A rule that has never fired proves nothing.
 */
final class LayerDependencyRuleTest extends TestCase
{
    private const PRODUCTION_CONFIG = 'deptrac.yaml';

    private const VIOLATING_FIXTURE_CONFIG = 'tests/Architecture/Fixtures/LayerRule/deptrac.fixture.yaml';

    #[Test]
    public function the_source_tree_satisfies_the_layer_dependency_rule(): void
    {
        [$exitCode, $output] = self::runDeptrac(self::PRODUCTION_CONFIG);

        self::assertSame(
            0,
            $exitCode,
            "BE-003 requires dependencies to point inward only. deptrac reported:\n".$output,
        );
    }

    #[Test]
    public function the_layer_dependency_rule_reports_an_outward_reference_from_the_domain(): void
    {
        [$exitCode, $output] = self::runDeptrac(self::VIOLATING_FIXTURE_CONFIG);

        self::assertSame(
            1,
            $exitCode,
            'BE-002 forbids a framework type in the Domain namespace, but the rule did not fail on a fixture that contains one.',
        );
        self::assertStringContainsString('PretendDomainClass', $output);
        self::assertStringContainsString('must not depend on', $output);
    }

    #[Test]
    public function the_production_configuration_declares_no_suppression(): void
    {
        /** @var array{deptrac: array<string, mixed>} $config */
        $config = Yaml::parseFile(self::basePath(self::PRODUCTION_CONFIG));

        // TC-042: a rule producing a false positive shall be narrowed, never
        // suppressed. TC-038 makes several of the rules non-suppressible outright.
        self::assertArrayNotHasKey('skip_violations', $config['deptrac']);
        self::assertArrayNotHasKey('baseline', $config['deptrac']);
        self::assertArrayNotHasKey('ignore_uncovered_internal_classes', $config['deptrac']);
    }

    /**
     * @return array{int, string}
     */
    private static function runDeptrac(string $configFile): array
    {
        $command = sprintf(
            '%s %s analyse --config-file=%s --no-progress --no-ansi 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(self::basePath('vendor/deptrac/deptrac/deptrac')),
            escapeshellarg(self::basePath($configFile)),
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        return [$exitCode, implode(PHP_EOL, $output)];
    }

    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
