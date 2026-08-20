<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Interface\Rest\ServedVersions;
use PHPUnit\Framework\TestCase;

/**
 * `CMP-IMP-469` — intents are resources, and paths say so.
 *
 * `AADR-07` decided that an intent — accepting, cancelling, verifying, starting,
 * completing — is modelled as a **sub-resource** rather than as a verb bolted to
 * a path. `API-006` states the rule: *"A path segment shall not encode an action
 * where a method expresses it."* `API-005` adds that collections are plural and
 * members are named by identifier.
 *
 * The reason is `API-004`: an operation is identified by a path **and a method**,
 * and has one meaning. `POST /rides/{id}/cancel` and `POST /rides/{id}/cancellations`
 * do the same thing, but the first makes the path carry the verb the method
 * already carries, and the second gives the intent a place to be a record — which
 * is what it turns out to need the moment somebody asks when it was submitted.
 *
 * ## The rule lands before the routes
 *
 * `routes/api.php` declares one operation today, because CMP-DOC-10 §11's
 * catalogue needs application services and `BE-017`'s nine aggregates are
 * unbuilt. The rule is written now so that the first intent resource is checked
 * by something that already existed, rather than by a reviewer remembering
 * `AADR-07`.
 */
final class RestRoutingRulesTest extends TestCase
{
    /**
     * Segments that name an action a method already expresses.
     *
     * Drawn from `AADR-07`'s own list of intents plus the verbs a REST surface
     * accretes. `TC-041` — narrow: each is a whole segment, so `cancellations`
     * and `verifications` (the resource forms) are not caught by `cancel` and
     * `verify`.
     *
     * @var list<string>
     */
    private const ACTION_SEGMENTS = [
        'accept', 'cancel', 'verify', 'start', 'complete', 'confirm', 'reject',
        'create', 'update', 'delete', 'remove', 'add', 'set', 'get', 'fetch',
        'submit', 'approve', 'decline', 'activate', 'deactivate', 'enable', 'disable',
    ];

    /**
     * The paths CMP-DOC-10 §9.1 makes reachable without a session, that this
     * surface serves.
     *
     * §9.1 names **five** operations: version discovery, session establishment,
     * phone verification initiation and completion, the public configuration
     * subset, and platform health. Three are routed. The other two are not built
     * — session establishment and both verification operations are blocked at
     * `CC-034` — so naming them here would exempt paths that do not exist.
     *
     * `API-110` puts this list in §9.1 *"and nowhere else"*, which is why it is
     * asserted against rather than merely used.
     *
     * @var list<string>
     */
    private const REACHABLE_WITHOUT_A_SESSION = ['versions', 'health', 'configuration'];

    public function test_no_path_segment_encodes_an_action(): void
    {
        // API-006 / AADR-07.
        $offenders = [];

        foreach (self::declaredPaths() as $path) {
            foreach (explode('/', $path) as $segment) {
                if (in_array(strtolower($segment), self::ACTION_SEGMENTS, true)) {
                    $offenders[] = $path.' → '.$segment;
                }
            }
        }

        self::assertSame([], $offenders, 'API-006: a method expresses the action; a path segment does not repeat it.');
    }

    public function test_the_rule_recognises_a_verb_on_a_path(): void
    {
        // TC-041 / TC-042: the true positive it exists for, and the false
        // positive it must not produce.
        self::assertContains('cancel', self::ACTION_SEGMENTS);
        self::assertNotContains('cancellations', self::ACTION_SEGMENTS);
        self::assertNotContains('verifications', self::ACTION_SEGMENTS);
    }

    public function test_the_version_is_the_first_path_segment(): void
    {
        // API-019 ‡. Registered under a {version} parameter rather than a literal
        // v1, so that an unserved version reaches RequireSupportedVersion and gets
        // API-024 ‡'s outcome instead of the 404 it forbids.
        $routes = self::routeFile();

        self::assertStringContainsString("ServedVersions::PREFIX.'/{version}'", $routes);
        self::assertStringNotContainsString("prefix('api/v1')", $routes);
        self::assertSame('api', ServedVersions::PREFIX);
    }

    public function test_every_route_passes_through_the_version_and_media_type_middleware(): void
    {
        // API-024 ‡ and API-011 are properties of the surface, not of individual
        // operations. A route registered outside the group would have neither.
        $routes = self::routeFile();

        self::assertStringContainsString('RequireSupportedVersion::class', $routes);
        self::assertStringContainsString('ServeJsonOnly::class', $routes);

        // One outer group, so there is no second place a route could be
        // registered without them. The inner group adds RequireSession to a
        // subset; it cannot remove what the outer one applied.
        self::assertSame(1, substr_count($routes, "->middleware([\n"));
    }

    public function test_every_operation_outside_section_9_1_sits_behind_a_session(): void
    {
        // API-110: "which operations are reachable without a session is stated in
        // §9.1 and nowhere else." API-095 ‡ requires everything else to carry a
        // session, so every other declared path must sit inside the
        // RequireSession group — and this fails if one is ever added outside it.
        $routes = self::routeFile();
        $guarded = substr($routes, strpos($routes, 'RequireSession::class') ?: 0);

        foreach (self::declaredPaths() as $path) {
            if (in_array($path, self::REACHABLE_WITHOUT_A_SESSION, true)) {
                continue;
            }

            self::assertStringContainsString(
                "'".$path."'",
                $guarded,
                'API-095 ‡: '.$path.' is not among CMP-DOC-10 §9.1\'s five, so it requires an authenticated session.',
            );
        }

        // And none of §9.1's is behind it. API-026 needs the range reachable
        // before a client can authenticate, and BE-203's health indication is
        // most needed exactly when the capability that authenticates is
        // withdrawn.
        foreach (self::REACHABLE_WITHOUT_A_SESSION as $exempt) {
            self::assertStringNotContainsString("'".$exempt."'", $guarded);
        }
    }

    public function test_the_unauthenticated_set_is_bounded_by_section_9_1(): void
    {
        // The other direction, and the one that matters more: API-110 puts the
        // list in §9.1 "and nowhere else", so the exemption above is not a list
        // this test may grow. Three of §9.1's five are unrouted — session
        // establishment and both verification operations are blocked at CC-034 —
        // and this fails if a sixth path is ever exempted here.
        self::assertSame(['versions', 'health', 'configuration'], self::REACHABLE_WITHOUT_A_SESSION);

        foreach (self::REACHABLE_WITHOUT_A_SESSION as $exempt) {
            self::assertContains(
                $exempt,
                self::declaredPaths(),
                'An exemption for a path the surface does not serve is an exemption nobody can review.',
            );
        }
    }

    /**
     * The paths `routes/api.php` declares, relative to the version prefix.
     *
     * @return list<string>
     */
    private static function declaredPaths(): array
    {
        preg_match_all(
            '/Route::(?:get|post|put|patch|delete)\(\s*\'([^\']*)\'/',
            self::routeFile(),
            $matches,
        );

        /** @var list<string> $paths */
        $paths = $matches[1];

        return $paths;
    }

    private static function routeFile(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/routes/api.php');
        self::assertIsString($contents);

        return $contents;
    }
}
