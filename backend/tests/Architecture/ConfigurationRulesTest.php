<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Application\Shared\Configuration\DeliveredValue;
use Cmp\Application\Shared\Configuration\ServeConfiguration;
use Cmp\Domain\Shared\Refusal\RefusalReason;
use Cmp\Domain\Shared\StateMachine\StateMachineRefusal;
use Cmp\Infrastructure\Laravel\Providers\ConfigurationServiceProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionParameter;
use SplFileInfo;

/**
 * `AADR-14` / CMP-DOC-10 §14 — the identifier set is part of the contract.
 *
 * `AADR-14`'s stated consequences are the whole of this file: *"reasons are
 * enumerable and testable"*, and *"the identifier set becomes part of the contract
 * and is versioned with it"*. Neither is true unless something enumerates the set
 * and fails the build when it falls behind — a refusal reason the platform can
 * return but the configuration resource does not deliver is a refusal a client has
 * no localised text for, and `API-083` would have it fall back to the default
 * every time.
 */
final class ConfigurationRulesTest extends TestCase
{
    public function test_every_refusal_reason_the_platform_declares_is_delivered(): void
    {
        // AADR-14: enumerable. If this fails, a RefusalReason was added and the
        // register was not — so a client would meet an identifier it has no text
        // for, which is the alternative AADR-14 rejected as "a blank dialog".
        $delivered = array_map(
            static fn (RefusalReason $reason): string => $reason::class,
            ConfigurationServiceProvider::refusalReasons(),
        );

        foreach (self::declaredReasonTypes() as $type) {
            self::assertContains(
                $type,
                $delivered,
                $type.' implements RefusalReason and is not delivered by CMP-DOC-10 §14.2\'s register.',
            );
        }
    }

    public function test_no_identifier_is_declared_twice_across_the_platform(): void
    {
        // API-084: a reason identifier is not repurposed within a version. Two
        // types sharing one identifier would make that unenforceable — a client
        // keying its text by the identifier would show one meaning for two
        // refusals.
        //
        // StateMachineRefusal is the deliberate exception and is asserted
        // separately below: API-086 ‡ and API-094 ‡ require two of its three
        // cases to be indistinguishable to a caller.
        $seen = [];

        foreach (ConfigurationServiceProvider::refusalReasons() as $reason) {
            $identifier = $reason->identifier();

            $seen[$identifier] ??= [];
            $seen[$identifier][] = $reason::class;
        }

        foreach ($seen as $identifier => $types) {
            self::assertSame(
                [array_unique($types)[0]],
                array_values(array_unique($types)),
                'API-084: "'.$identifier.'" is declared by more than one type.',
            );
        }
    }

    public function test_two_lifecycle_cases_deliberately_share_one_identifier(): void
    {
        // The exception, asserted so it stays deliberate. API-086 ‡ / API-094 ‡:
        // a caller who could tell "not a declared transition" from "declared, but
        // an invariant forbids it" could map the platform's lifecycle by probing.
        $identifiers = array_map(
            static fn (RefusalReason $reason): string => $reason->identifier(),
            StateMachineRefusal::cases(),
        );

        self::assertCount(3, $identifiers);
        self::assertCount(2, array_unique($identifiers));
    }

    public function test_every_delivered_reason_carries_a_default_text(): void
    {
        // API-082 ‡ travels with API-081 ‡. An identifier with no default is an
        // identifier an older client shows as nothing at all.
        foreach (ConfigurationServiceProvider::refusalReasons() as $reason) {
            self::assertNotSame('', trim($reason->defaultText()), $reason->identifier().' has no default text.');
            self::assertNotSame(
                $reason->identifier(),
                $reason->defaultText(),
                $reason->identifier().': the default is text for a person, not the identifier again.',
            );
        }
    }

    public function test_the_public_subset_is_declared_per_value_and_not_decided_by_a_caller(): void
    {
        // API-195: the public subset "shall contain no value that discloses
        // platform state". Two things make that structural rather than
        // remembered, and both are asserted here.
        //
        // First, publicness is a **constructor argument** of every entry, so a
        // value cannot be registered without somebody deciding it — there is no
        // default to fall through.
        foreach ([DeliveredValue::class] as $entry) {
            $constructor = (new ReflectionClass($entry))->getConstructor();

            self::assertNotNull($constructor);
            self::assertContains(
                'public',
                array_map(
                    static fn (ReflectionParameter $parameter): string => $parameter->getName(),
                    $constructor->getParameters(),
                ),
            );
        }

        // Second, the two subsets are **two methods**, so the day a non-public
        // value is added, the unauthenticated route continues to serve the subset
        // without anybody having to remember to filter it.
        foreach (['public', 'all'] as $method) {
            self::assertTrue(
                method_exists(ServeConfiguration::class, $method),
                'API-195: the public subset and the full set are distinct callers, not one with a flag.',
            );
        }

        // And at least one value is public, or §9.1's "configuration fetch,
        // public subset" would name a resource that serves nothing.
        self::assertNotSame([], array_filter(
            ConfigurationServiceProvider::delivered(),
            static fn (DeliveredValue $value): bool => $value->isPublic(),
        ));
    }

    public function test_no_value_outside_section_14_2_is_delivered(): void
    {
        // §14.2 is a closed list of eight, and API-187 ‡ makes this resource the
        // client's whole source of policy values — so delivering something §14.2
        // does not name would be adding to the contract here rather than in
        // CMP-DOC-10. Two are delivered; the provider's own table says why the
        // other six are not.
        $names = array_map(
            static fn (DeliveredValue $value): string => $value->name(),
            ConfigurationServiceProvider::delivered(),
        );

        self::assertSame(['session_lifetime_seconds', 'refusal_reasons'], $names);
    }

    /**
     * Every type in `src/` that implements `RefusalReason`.
     *
     * Found by reading the tree rather than by a list, for the same reason
     * `StructuralRulesTest` reads the tree: a list is a thing that falls behind,
     * and this test exists precisely to catch something falling behind.
     *
     * @return list<class-string<RefusalReason>>
     */
    private static function declaredReasonTypes(): array
    {
        $found = [];
        $root = dirname(__DIR__, 2).'/src';

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents) || ! str_contains($contents, 'implements RefusalReason')) {
                continue;
            }

            if (preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
                continue;
            }

            $class = $namespace[1].'\\'.$file->getBasename('.php');

            if (! class_exists($class) && ! enum_exists($class)) {
                continue;
            }

            $reflection = enum_exists($class) ? new ReflectionEnum($class) : new ReflectionClass($class);

            if ($reflection->implementsInterface(RefusalReason::class)) {
                /** @var class-string<RefusalReason> $class */
                $found[] = $class;
            }
        }

        self::assertNotSame([], $found, 'The platform declares at least one refusal reason.');

        return $found;
    }
}
