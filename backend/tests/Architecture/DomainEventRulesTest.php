<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Application\Shared\Event\DomainEventListener;
use Cmp\Application\Shared\Event\ListenerRegistry;
use Cmp\Domain\Shared\Event\DomainEvent;
use Cmp\Domain\User\Event\PhoneNumberVerified;
use Cmp\Domain\User\Event\UserRegistered;
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

    /**
     * `TC-024` ‡ — every domain event in the tree is examined, and which ones
     * those are is stated.
     *
     * `CC-054`. The rules above were enforced against whatever PHP **happened to
     * have loaded**: the subject came from `get_declared_classes()`, and a class
     * nothing in the run had referenced was not declared. Today the only member is
     * the test double, appended by name because doubles load lazily — and that
     * special case was the tell. The platform's own events arrive with `BE-017`'s
     * nine aggregates, and each would have been invisible to `BE-039` until some
     * unrelated test happened to autoload it. A non-final event with a public
     * mutable property was placed in `src/Domain` and all six rules passed.
     *
     * **And the platform already had two.** `UserRegistered` and
     * `PhoneNumberVerified` have been in `src/Domain/User/Event` since FEAT-001,
     * both `final` with `readonly` properties — and `BE-039` was asserted of
     * neither, because nothing in an architecture run loads a Domain event. Both
     * files say in their own docblocks that `DomainEventRulesTest` asserts it of
     * every implementation. It did not.
     *
     * The subject is now read from the tree, and asserted by identity so that
     * "nothing broke the rule" cannot again mean "nothing was read".
     *
     * @var array<class-string<DomainEvent>, string>
     */
    private const EVENTS = [
        PhoneNumberVerified::class => 'FRD-FR-008 / BAD-RULE-006: the platform\'s own account of having decided '
            .'that control of a number was demonstrated.',
        UserRegistered::class => 'FRD-FR-001 / FRD-FR-006: an account came into existence. It carries a '
            .'reference and not a number, because BE-201 ‡ keeps a contact detail out of anything a listener '
            .'or a record can reach.',
        ThingHappened::class => 'A test double, and a member because BE-039 is asserted of every implementation. '
            .'It is what kept these rules from running on nothing while the two above were invisible to them.',
    ];

    public function test_every_domain_event_in_the_tree_is_examined(): void
    {
        self::assertSame(
            array_keys(self::EVENTS),
            self::domainEventClasses(),
            'BE-039 is asserted of the events this file can see. An event in the tree and missing from here is '
            .'one the rules never examined; an entry here that is not in the tree is a rule asserting something '
            .'about nothing. An event added with an aggregate belongs in this list on the same commit.',
        );
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
     * Every implementation of {@see DomainEvent} in the tree.
     *
     * Read from the **files**, not from `get_declared_classes()`. A class PHP has
     * not loaded is not declared, and autoloading is driven by what a run happens
     * to touch — so a subject derived that way shrinks and grows with the test
     * order and is never the whole of it. Resolving the name from the file and
     * asking `is_subclass_of()` loads each candidate on purpose.
     *
     * The doubles directory is a root because the double is held to `BE-039` too:
     * an immutability rule that exempted the one implementation it could reach
     * would have nothing left to run on.
     *
     * @return list<class-string<DomainEvent>>
     */
    private static function domainEventClasses(): array
    {
        $classes = [];

        foreach ([self::root().'src', self::root().'tests/Domain/Shared/Doubles'] as $root) {
            foreach (self::phpFilesUnder($root) as $path) {
                $class = self::classDeclaredIn($path);

                if ($class === null || ! is_subclass_of($class, DomainEvent::class)) {
                    continue;
                }

                $classes[] = $class;
            }
        }

        sort($classes);

        /** @var list<class-string<DomainEvent>> $classes */
        return $classes;
    }

    /**
     * The class a file declares, or `null` where it declares none that exists.
     *
     * An interface or a trait is not a class, so {@see DomainEvent} itself and any
     * contract beside it drop out here rather than needing to be named.
     *
     * @return class-string|null
     */
    private static function classDeclaredIn(string $path): ?string
    {
        $contents = file_get_contents($path);

        self::assertIsString($contents);

        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $matches) !== 1) {
            return null;
        }

        $class = trim($matches[1]).'\\'.basename($path, '.php');

        return class_exists($class) ? $class : null;
    }

    /**
     * @return list<string>
     */
    private static function phpFilesUnder(string $root): array
    {
        $paths = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2).'/';
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
