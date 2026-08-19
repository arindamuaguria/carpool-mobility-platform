<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Infrastructure\Laravel\Providers\AuthorisationServiceProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;

/**
 * CMP-IMP-033 — the structural half of one authorisation path.
 *
 * `SADR-06` makes a single evaluation the whole defence of `TB-1` and `TB-2`, and
 * rejected the alternative in its own words: *"two paths diverge, and the one
 * used less is the one that rots."* A second path is not something a reviewer
 * will reliably notice, so the build fails on one.
 */
final class AuthorisationRulesTest extends TestCase
{
    /**
     * `SEC-054` ‡ / `BE-180` ‡: authorisation is **not** implemented in transport
     * middleware alone. The interface layer may authenticate; it may not decide.
     */
    private const INTERFACE_DIRECTORY = 'src/Interface/';

    /**
     * Types that would mean an entitlement came from a request rather than from
     * platform state (`SEC-045` ‡, `BE-181` ‡).
     */
    private const REQUEST_TYPES = ['Request', 'Session', 'Token', 'Jwt', 'Cookie', 'Header'];

    public function test_the_interface_layer_never_decides_an_authorisation(): void
    {
        // SEC-054 ‡ / BE-180 ‡. SADR-06 rejected middleware-only authorisation
        // because "a queue worker or a Filament resource bypassing the HTTP stack
        // would bypass authorisation entirely."
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_starts_with($relative, self::INTERFACE_DIRECTORY)) {
                continue;
            }

            foreach (['Authoriser', 'AuthorisationPolicy', 'AuthorisationRule'] as $decider) {
                if (str_contains($contents, $decider)) {
                    $offenders[] = $relative.' → '.$decider;
                }
            }
        }

        self::assertSame([], $offenders, 'SEC-054 ‡: the interface layer authenticates; it does not decide.');
    }

    public function test_the_authorisation_namespace_takes_nothing_from_a_request(): void
    {
        // SEC-045 ‡: "A session shall carry no authorisation claim; entitlement
        // shall be evaluated against platform state on every request."
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_starts_with($relative, 'src/Application/Shared/Authorisation/')) {
                continue;
            }

            foreach (self::REQUEST_TYPES as $type) {
                if (preg_match('/\b'.$type.'\b/', $contents) === 1 && ! str_contains($contents, 'on every request')) {
                    $offenders[] = $relative.' → '.$type;
                }
            }
        }

        self::assertSame([], $offenders, 'SEC-045 ‡: an actor is resolved from platform state, never from a claim.');
    }

    public function test_authorisation_happens_in_the_application_service_base(): void
    {
        // BE-044 / SEC-053 ‡: evaluated before the domain is invoked, on every
        // caller. Putting it in the base is what makes it impossible to omit.
        $contents = file_get_contents(self::basePath('src/Application/Shared/ApplicationService.php'));
        self::assertIsString($contents);

        self::assertStringContainsString('$this->authoriser->authorise(', $contents);

        // final, so a service cannot replace the path that authorises.
        $execute = new ReflectionMethod(ApplicationService::class, 'execute');
        self::assertTrue($execute->isFinal(), 'A service must not be able to override execute() and skip authorisation.');
    }

    public function test_the_evaluation_cannot_hand_back_an_exemption(): void
    {
        // SEC-010 ‡ / SEC-062 ‡ / SD-04: "An operator gains capability, never
        // exemption." A void return has nothing an exemption could ride on, and a
        // permitted operation still runs every domain invariant afterwards.
        $authorise = new ReflectionMethod(Authoriser::class, 'authorise');

        self::assertSame('void', (string) $authorise->getReturnType());
    }

    public function test_the_platform_policy_is_empty_and_the_role_set_is_blocked(): void
    {
        // SEC-055 ‡: an operation with no stated rule is refused, and no
        // application service exists to state one for.
        //
        // SEC-063: "Role definitions and their capabilities are [TBD – Business
        // Decision Required]; the mechanism is specified and the role set is
        // not." BAD-DEC-006 is open and ADM-168 records that the administrative
        // unit cannot start without it.
        self::assertSame(0, AuthorisationServiceProvider::policy()->count());
        self::assertSame([], AuthorisationServiceProvider::roles());
    }

    public function test_a_policy_absent_of_an_operation_states_no_rule_for_it(): void
    {
        // The deny-by-default behaviour is a property of the policy, not of the
        // caller remembering to check.
        $policy = AuthorisationPolicy::of([]);

        self::assertFalse($policy->statesARuleFor(Operation::named('test.anything')));
        self::assertNull($policy->ruleFor(Operation::named('test.anything')));
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
