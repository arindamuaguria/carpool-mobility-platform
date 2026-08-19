<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Failure\DependencyUnavailable;
use Cmp\Application\Shared\Failure\Failure;
use Cmp\Application\Shared\Failure\FailureBranch;
use Cmp\Application\Shared\Failure\InternalFault;
use Cmp\Application\Shared\Failure\InvalidRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * CMP-IMP-034 — the error model is closed at four branches.
 *
 * `API-071` ‡ requires every failure to be exactly one of four branches, and
 * `API-072` ‡ requires each to be distinguishable by structure alone. PHP has no
 * sealed class, so the closure is asserted here instead of declared.
 */
final class ErrorModelStructureTest extends TestCase
{
    private const BRANCH_CLASSES = [
        InvalidRequest::class,
        BusinessRefused::class,
        DependencyUnavailable::class,
        InternalFault::class,
    ];

    #[Test]
    public function exactly_four_failure_classes_exist(): void
    {
        $found = [];

        foreach (self::failureSourceFiles() as $file) {
            $class = 'Cmp\\Application\\Shared\\Failure\\'.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Failure::class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);
        $expected = self::BRANCH_CLASSES;
        sort($expected);

        self::assertSame(
            $expected,
            $found,
            'API-071 ‡: a failure is exactly one of four branches. A fifth concrete Failure would break that.',
        );
    }

    #[Test]
    public function every_failure_class_is_final(): void
    {
        foreach (self::BRANCH_CLASSES as $class) {
            self::assertTrue(
                (new ReflectionClass($class))->isFinal(),
                $class.' must be final; a subclass would add a branch outside the four of API-071 ‡.',
            );
        }
    }

    #[Test]
    public function the_branch_enum_is_closed_at_four_cases(): void
    {
        self::assertCount(4, FailureBranch::cases());
    }

    #[Test]
    public function the_internal_fault_cannot_hold_a_throwable(): void
    {
        // API-092 ‡ / BE-189 ‡: no stack, no query, no internal component. The
        // constraint is structural — a constructor that cannot accept a cause
        // cannot leak one.
        $constructor = (new ReflectionClass(InternalFault::class))->getConstructor();
        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = (string) $parameter->getType();

            self::assertStringNotContainsString('Throwable', $type);
            self::assertStringNotContainsString('Exception', $type);
        }
    }

    /**
     * @return list<string>
     */
    private static function failureSourceFiles(): array
    {
        $directory = dirname(__DIR__, 2).'/src/Application/Shared/Failure';
        $files = glob($directory.'/*.php');

        self::assertIsArray($files);

        /** @var list<string> $files */
        return $files;
    }
}
