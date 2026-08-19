<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Application\Shared\Event\DomainEventListener;
use Cmp\Application\Shared\Event\ListenerRegistry;
use Cmp\Domain\Shared\Event\DomainEvent;
use Cmp\Infrastructure\Laravel\Providers\EventServiceProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\Domain\Shared\Doubles\ThingHappened;

/**
 * CMP-IMP-026 — the structural half of after-commit dispatch.
 *
 * `BE-039` requires a domain event to be **immutable**. PHP has no way to
 * declare that on an interface, so it is asserted: every implementation is
 * `final` and every property `readonly`. An event a listener could mutate would
 * mean the second listener sees something different from the first, and a
 * listener that enqueued a job would be serialising a value that had already
 * changed under it.
 *
 * `BE-064` requires event subscription to be declared in **one** registry. A
 * second `subscribe` call somewhere else would be a subscription nobody
 * reviewing the catalogue could see.
 */
final class DomainEventRulesTest extends TestCase
{
    /**
     * The one place subscriptions are declared (`BE-064`).
     */
    private const SUBSCRIPTION_DECLARATION = 'src/Infrastructure/Laravel/Providers/EventServiceProvider.php';

    public function test_every_domain_event_is_final(): void
    {
        foreach (self::domainEventClasses() as $class) {
            self::assertTrue(
                (new ReflectionClass($class))->isFinal(),
                $class.' must be final; BE-039 requires a domain event to be immutable, and a subclass could add mutable state.',
            );
        }
    }

    public function test_every_domain_event_property_is_readonly(): void
    {
        foreach (self::domainEventClasses() as $class) {
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                self::assertTrue(
                    $property->isReadOnly(),
                    sprintf('BE-039: %s::$%s must be readonly.', $class, $property->getName()),
                );
                self::assertFalse(
                    $property->isPublic(),
                    sprintf('%s::$%s must not be public; an event exposes accessors, not fields.', $class, $property->getName()),
                );
            }
        }
    }

    public function test_no_domain_event_declares_a_setter(): void
    {
        foreach (self::domainEventClasses() as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                self::assertFalse(
                    str_starts_with($method->getName(), 'set') || str_starts_with($method->getName(), 'with'),
                    sprintf('BE-039: %s::%s() suggests a domain event can be changed after it happened.', $class, $method->getName()),
                );
            }
        }
    }

    public function test_at_least_one_domain_event_exists_to_check(): void
    {
        // A rule with nothing to run on proves nothing. The platform's own events
        // arrive with the nine aggregates of BE-017; until then the test double
        // is what keeps this suite honest.
        self::assertNotEmpty(self::domainEventClasses());
    }

    public function test_subscriptions_are_declared_in_exactly_one_place(): void
    {
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if ($relative === self::SUBSCRIPTION_DECLARATION) {
                continue;
            }

            if (str_contains($contents, '->subscribe(')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'BE-064: event subscription is declared in one registry, inspectable as a catalogue.',
        );
    }

    public function test_the_declared_catalogue_is_what_the_registry_holds(): void
    {
        // BE-064 asks for a catalogue, which means something a person can read
        // and rely on. A static declaration that the registry then diverged from
        // would be worse than none.
        $registry = new ListenerRegistry;

        foreach (EventServiceProvider::subscriptions() as $eventName => $listeners) {
            foreach ($listeners as $listener) {
                self::assertTrue(
                    is_a($listener, DomainEventListener::class, true),
                    $listener.' is declared as a listener but does not implement the contract.',
                );
                $registry->subscribe($eventName, $listener);
            }
        }

        self::assertSame(EventServiceProvider::subscriptions(), $registry->catalogue());
    }

    /**
     * @return list<class-string<DomainEvent>>
     */
    private static function domainEventClasses(): array
    {
        $classes = [];

        foreach (get_declared_classes() as $class) {
            if (! is_subclass_of($class, DomainEvent::class)) {
                continue;
            }

            $classes[] = $class;
        }

        // Test doubles are loaded lazily, so make sure the one that exists is
        // present before the rules above run against an empty set.
        if (! in_array(ThingHappened::class, $classes, true)
            && class_exists(ThingHappened::class)) {
            $classes[] = ThingHappened::class;
        }

        /** @var list<class-string<DomainEvent>> $classes */
        return $classes;
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

            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $contents;
        }

        return $files;
    }
}
