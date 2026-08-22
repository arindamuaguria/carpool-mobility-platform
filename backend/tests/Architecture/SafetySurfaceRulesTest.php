<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `AADR-11` / `BADR-16` — the safety surface's isolation, checked.
 *
 * **Level 1** (`TC-025`). Every rule here is a ‡ statement about what the safety
 * capability may depend on, and every one of them is true today for the trivial
 * reason that payment, search, matching, rating and projection do not exist yet.
 * `TC-041`'s discipline applies: a rule that passes because there is nothing to
 * catch proves nothing, so each detector below is **validated in both
 * directions** in
 * {@see test_the_safety_detectors_recognise_what_their_rules_exist_for()}.
 *
 * The rules matter now rather than later precisely because those components do
 * not exist. When FEAT-016 brings payment and FEAT-011 brings search, the first
 * import that reaches into the safety surface will fail a build rather than a
 * review — which is `BADR-18`'s whole argument for mechanical enforcement.
 */
final class SafetySurfaceRulesTest extends TestCase
{
    /**
     * `BE-193` ‡ — *"The safety surface shall not depend on payment, search,
     * matching, rating or projection components."*
     *
     * `AADR-11` gives the reason in `NFR-030`'s terms: safety must not degrade
     * because unrelated capability is failing. `API-166` ‡ says the same of
     * raising an incident. CMP-DOC-10 §12.3 puts it plainest — *"every
     * dependency is a way to fail."*
     *
     * @var list<string>
     */
    private const FORBIDDEN_DEPENDENCIES = [
        'Payment', 'Search', 'Matching', 'Rating', 'Projection',
    ];

    /**
     * Where the safety capability lives.
     *
     * `BADR-16`: it *"uses the same application services, the same domain and
     * the same store"*, so the shared platform machinery — evidence,
     * authorisation, transactions, the clock — is not part of this set and is
     * not meant to be. What is here is the code that exists **only** for safety,
     * and it is the code `BE-193` ‡ binds.
     *
     * @var list<string>
     */
    private const SAFETY_PATHS = [
        'src/Domain/Safety/',
        'src/Application/Safety/',
        'src/Infrastructure/Safety/',
        'src/Infrastructure/Persistence/Safety/',
        'src/Interface/Safety/',
        'src/Infrastructure/Job/RouteSafetyIncident.php',
        'src/Infrastructure/Laravel/Providers/SafetyServiceProvider.php',
    ];

    public function test_the_safety_surface_depends_on_none_of_the_five(): void
    {
        // BE-193 ‡ / API-166 ‡.
        $offenders = [];

        foreach (self::safetyFiles() as $relative => $contents) {
            foreach (self::forbiddenDependenciesIn($contents) as $dependency) {
                $offenders[] = $relative.' → '.$dependency;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'BE-193 ‡: the safety surface depends on no payment, search, matching, rating or projection '
            .'component. NFR-030: safety must not degrade because unrelated capability is failing.',
        );
    }

    public function test_the_safety_surface_reads_no_configuration(): void
    {
        // API-194 ‡: "Failure to fetch configuration shall not prevent the client
        // from raising a safety incident." CMP-DOC-10 §12.3 omits configuration
        // fetch from the surface for the same reason — it "would make recording
        // depend on configuration".
        //
        // The general surface stamps a configuration version on every envelope.
        // This one must not, however tolerantly the read fails.
        $offenders = [];

        foreach (self::safetyFiles() as $relative => $contents) {
            foreach (['ConfigurationVersion', 'ServeConfiguration', 'PolicyStore'] as $reader) {
                if (self::namesTypeInCode($contents, $reader)) {
                    $offenders[] = $relative.' → '.$reader;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'API-194 ‡: raising a safety incident does not depend on configuration.',
        );
    }

    public function test_nothing_on_the_safety_path_is_throttled(): void
    {
        // BE-196 ‡ / API-168 ‡ / OPS-046: "a safety operation shall never be
        // rate-limited to the point of refusal", and safety traffic is exempt
        // from every rate limit, quota and throttle.
        //
        // No rate limiter exists anywhere in the platform yet. This rule is
        // written now so that the first one cannot be applied here by a group
        // declaration somebody adds without reading AADR-11.
        // Read as code, not as text. The route file's own comment explains that
        // nothing here may be throttled, and a detector that flagged the
        // explanation would be one whose only fix is to delete the explanation —
        // the disabling TC-024 ‡ forbids. A middleware applied as a string
        // (`'throttle:60,1'`) is still caught, because a string literal is code.
        $routes = self::codeOf(self::routeFile('routes/safety.php'));
        $offenders = [];

        foreach (['throttle', 'RateLimit', 'rateLimit', 'quota', 'Throttle'] as $limiter) {
            if (str_contains($routes, $limiter)) {
                $offenders[] = $limiter;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'BE-196 ‡ / OPS-046: safety traffic is exempt from every rate limit, quota and throttle.',
        );
    }

    public function test_the_safety_surface_answers_under_its_own_prefix(): void
    {
        // API-163 ‡: "Safety operations shall be served under a path prefix
        // distinct from the general surface." AADR-11's reason is operational —
        // a gateway rule written for the general case will otherwise reach it.
        $safety = self::routeFile('routes/safety.php');
        $general = self::routeFile('routes/api.php');

        self::assertStringContainsString('SafetySurface::PREFIX', $safety);
        self::assertStringNotContainsString('ServedVersions::PREFIX', $safety);
        self::assertStringNotContainsString('SafetySurface', $general);
    }

    public function test_no_safety_operation_is_declared_on_the_general_surface(): void
    {
        // API-170 ‡: "The safety surface shall be specifiable and servable by a
        // deployment implementing only these operations." A safety route in
        // routes/api.php would make that deployment impossible without a code
        // change, and BE-198 says the separation is not conditional on whether
        // anybody deploys it that way.
        $general = self::routeFile('routes/api.php');

        self::assertStringNotContainsString('incidents', $general);
        self::assertStringNotContainsString('Interface\\Safety', $general);
    }

    /**
     * `TC-041` / `TC-024` ‡ — the detectors above are shown to fire.
     *
     * Four of the five forbidden dependencies name components that do not exist,
     * so every rule in this file passes on an empty set. That is exactly the
     * condition under which a broken detector looks like a satisfied rule.
     */
    public function test_the_safety_detectors_recognise_what_their_rules_exist_for(): void
    {
        // The dependency detector reads code, not prose — the same correction
        // TC-037 ‡ rule 11 needed. This file's own FORBIDDEN_DEPENDENCIES list
        // names all five, and this file is not a safety file, but a docblock in
        // one legitimately explains what it must not import.
        self::assertSame(
            ['Payment'],
            self::forbiddenDependenciesIn('use Cmp\Application\Payment\InitiatePayment;'),
        );

        self::assertSame(
            ['Projection'],
            self::forbiddenDependenciesIn('final class X { private ProjectionStore $store; }'),
        );

        self::assertSame(
            [],
            self::forbiddenDependenciesIn('// This must never import a Payment or Search component.'),
            'A file that writes about the prohibition is not a file that breaks it.',
        );

        self::assertSame(
            [],
            self::forbiddenDependenciesIn('use Cmp\Domain\Safety\SafetyIncident;'),
        );

        // The configuration detector, both ways.
        self::assertTrue(self::namesTypeInCode('use Cmp\Application\Shared\Configuration\ConfigurationVersion;', 'ConfigurationVersion'));
        self::assertFalse(
            self::namesTypeInCode('/** No ConfigurationVersion is stamped here — API-194 ‡. */', 'ConfigurationVersion'),
            'The class note explaining why configuration is absent is not a configuration read.',
        );

        // The throttle detector, both ways. It caught this file's own route
        // comment before it was corrected to read code — which is what a
        // detector validation is for.
        self::assertStringNotContainsString('throttle', self::codeOf('// nothing here is throttled'));
        self::assertStringContainsString('throttle', self::codeOf("Route::middleware('throttle:60,1');"));

        // And the paths it scans are real, or it scans nothing at all.
        self::assertNotSame([], self::safetyFiles());
    }

    /**
     * The forbidden component names a file **uses**, ignoring prose.
     *
     * Comments are stripped through the tokeniser, for the reason `TC-037` ‡
     * rule 11 was corrected: a docblock that explains a prohibition is not an
     * instance of breaking it, and deleting the explanation to satisfy a
     * detector is the disabling `TC-024` ‡ forbids.
     *
     * @return list<string>
     */
    private static function forbiddenDependenciesIn(string $contents): array
    {
        $code = self::codeOf($contents);
        $found = [];

        foreach (self::FORBIDDEN_DEPENDENCIES as $dependency) {
            if (preg_match('/(?<![A-Za-z])'.$dependency.'(?![a-z])/', $code) === 1) {
                $found[] = $dependency;
            }
        }

        return $found;
    }

    private static function namesTypeInCode(string $contents, string $type): bool
    {
        return str_contains(self::codeOf($contents), $type);
    }

    /**
     * A file's PHP with every comment removed.
     */
    private static function codeOf(string $contents): string
    {
        if (! str_contains($contents, '<?php')) {
            $contents = '<?php '.$contents;
        }

        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @return array<string, string>
     */
    private static function safetyFiles(): array
    {
        $files = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            foreach (self::SAFETY_PATHS as $path) {
                if (str_starts_with($relative, $path) || $relative === $path) {
                    $files[$relative] = $contents;

                    break;
                }
            }
        }

        return $files;
    }

    private static function routeFile(string $relative): string
    {
        $contents = file_get_contents(self::basePath($relative));

        self::assertIsString($contents, $relative.' is missing.');

        return $contents;
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
