<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CMP-IMP-021 — namespace roots, autoload mapping and domain-area organisation.
 *
 * Realises `BE-001` (four namespaces), `BE-004` (organised by domain area beneath
 * each), `BE-017` (the nine aggregates) and `BADR-01` (domain-centric namespaces,
 * not framework-default MVC).
 */
final class LayerStructureTest extends TestCase
{
    /** The four layers of `BE-001`, mapped to their source roots. */
    private const LAYERS = [
        'Domain' => 'src/Domain/',
        'Application' => 'src/Application/',
        'Infrastructure' => 'src/Infrastructure/',
        'Interface' => 'src/Interface/',
    ];

    /** The nine aggregates fixed by `BE-017`. */
    private const AGGREGATES = [
        'User',
        'Vehicle',
        'Ride',
        'RideRequest',
        'Booking',
        'Payment',
        'Trip',
        'SafetyIncident',
        'OperatorCase',
    ];

    /**
     * @return array<string, array{string, string}>
     */
    public static function layerProvider(): array
    {
        $cases = [];

        foreach (self::LAYERS as $layer => $path) {
            $cases[$layer] = [$layer, $path];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function aggregateProvider(): array
    {
        $cases = [];

        foreach (self::AGGREGATES as $aggregate) {
            $cases[$aggregate] = [$aggregate];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('layerProvider')]
    public function each_documented_layer_has_a_source_root(string $layer, string $path): void
    {
        self::assertDirectoryExists(
            self::basePath($path),
            "BE-001 requires a {$layer} namespace; {$path} does not exist.",
        );
    }

    #[Test]
    #[DataProvider('layerProvider')]
    public function each_documented_layer_is_mapped_in_the_autoloader(string $layer, string $path): void
    {
        $expected = self::namespaceFor($layer);

        self::assertArrayHasKey(
            $expected,
            self::psr4(),
            "BE-001 requires a {$layer} namespace autoloaded from {$path}.",
        );
        self::assertSame($path, self::psr4()[$expected]);
    }

    #[Test]
    #[DataProvider('aggregateProvider')]
    public function each_aggregate_has_a_domain_area_directory(string $aggregate): void
    {
        self::assertDirectoryExists(
            self::basePath('src/Domain/'.$aggregate),
            "BE-017 names {$aggregate} as an aggregate and BE-004 requires the Domain namespace to be organised by domain area.",
        );
        self::assertDirectoryExists(
            self::basePath('src/Application/'.$aggregate),
            'BE-004 requires the Application namespace to be organised by the same domain areas.',
        );
    }

    #[Test]
    public function the_framework_default_application_namespace_is_absent(): void
    {
        self::assertDirectoryDoesNotExist(
            self::basePath('app'),
            'BADR-01: the application is organised into the four documented namespaces, not the framework-default MVC layout.',
        );
        self::assertArrayNotHasKey('App\\', self::psr4());
    }

    #[Test]
    public function no_namespace_root_other_than_the_four_layers_is_autoloaded_from_src(): void
    {
        $permitted = array_map(self::namespaceFor(...), array_keys(self::LAYERS));

        foreach (self::psr4() as $namespace => $path) {
            if (! str_starts_with($path, 'src/')) {
                continue;
            }

            self::assertContains(
                $namespace,
                $permitted,
                "BE-001 fixes the layer set; {$namespace} is not one of the four.",
            );
        }
    }

    private static function namespaceFor(string $layer): string
    {
        return 'Cmp\\'.$layer.'\\';
    }

    private static function basePath(string $relative = ''): string
    {
        return dirname(__DIR__, 2).($relative === '' ? '' : '/'.$relative);
    }

    /**
     * @return array<string, string>
     */
    private static function psr4(): array
    {
        $contents = file_get_contents(self::basePath('composer.json'));
        self::assertIsString($contents);

        /** @var array{autoload: array{psr-4: array<string, string>}} $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return $decoded['autoload']['psr-4'];
    }
}
