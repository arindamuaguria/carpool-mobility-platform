<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Interface\Rest\ServedVersions;
use Cmp\Interface\Rest\UnsupportedVersion;
use Tests\TestCase;

/**
 * `CMP-IMP-461`, `CMP-IMP-462` — versioning and the supported range.
 *
 * **Level 4** (`TC-031`), and the first tests at this level. A contract test
 * asserts **shape, never business behaviour**: what follows checks that a
 * response says which version served it, that an unserved version gets its own
 * outcome rather than a `404`, and that the range is reachable without a session.
 * None of it asserts what any business operation does, because none exists and
 * because that would be level 5's.
 */
final class VersioningTest extends TestCase
{
    public function test_the_supported_range_is_reachable_without_a_session(): void
    {
        // API-027 / API-026 / §9.1: a client must be able to learn it is
        // unsupported before it can authenticate. No credential is sent here.
        $response = $this->getJson('/api/v1/versions');

        $response->assertOk();
        $response->assertJsonPath('data.supported', ServedVersions::all());
        $response->assertJsonPath('data.current', ServedVersions::CURRENT);
    }

    public function test_the_range_states_that_there_is_no_preceding_version(): void
    {
        // Exactly one version is served today. When a v2 is issued this fails,
        // and whoever issues it has to revisit API-023 deprecation marking
        // rather than discovering later that nothing was ever marked.
        self::assertCount(1, ServedVersions::all());
        self::assertSame([], ServedVersions::deprecated());

        // API-021 serves N and N−1; there has never been a v0, so `preceding` is
        // null and says so. A client can then tell "no preceding version" from
        // "the platform did not mention one".
        $this->getJson('/api/v1/versions')->assertJsonPath('data.preceding', null);
    }

    public function test_every_response_states_the_version_that_served_it(): void
    {
        // API-022, on every response and not only on a negotiation step.
        $response = $this->getJson('/api/v1/versions');

        $response->assertJsonPath('meta.interface_version', 1);
        $response->assertJsonPath('meta.deprecated', false);
    }

    public function test_every_response_carries_the_time_the_platform_evaluated_it(): void
    {
        // API-043 ‡ / AADR-09. The shape is asserted, not the value: API-015
        // requires a single unambiguous representation carrying an offset.
        // `Z` is that offset — Instant is UTC-only (DB-034 keeps the platform on
        // one time zone reference), so every stamp reads `Z` rather than +00:00.
        $evaluatedAt = $this->getJson('/api/v1/versions')->json('meta.evaluated_at');

        self::assertIsString($evaluatedAt);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}(Z|[+-]\d{2}:\d{2})$/',
            $evaluatedAt,
        );
    }

    public function test_an_unserved_version_receives_its_own_outcome_and_not_a_not_found(): void
    {
        // Negative test for API-024 ‡, and the point of the whole mechanism:
        // MOB-057 needs the client to enter a version-unsupported state, which it
        // cannot do if the platform says the path does not exist.
        $response = $this->getJson('/api/v9/versions');

        $response->assertStatus(UnsupportedVersion::STATUS);
        self::assertNotSame(404, $response->getStatusCode());
        $response->assertJsonPath(UnsupportedVersion::KEY.'.requested', 9);
    }

    public function test_the_unsupported_outcome_states_what_the_platform_does_serve(): void
    {
        // API-025. Without this the client would have to probe.
        $this->getJson('/api/v9/versions')
            ->assertJsonPath(UnsupportedVersion::KEY.'.supported', ServedVersions::all());
    }

    public function test_the_unsupported_outcome_is_reachable_without_a_session(): void
    {
        // API-026. Asserted by sending no credential and requiring the outcome
        // rather than an authentication refusal.
        $this->getJson('/api/v9/versions')->assertStatus(UnsupportedVersion::STATUS);
    }

    public function test_a_path_segment_that_is_not_a_version_is_unsupported_rather_than_not_found(): void
    {
        // The same reasoning as API-024 ‡: anything in the version segment that
        // the platform does not serve is a version it does not serve.
        $response = $this->getJson('/api/latest/versions');

        $response->assertStatus(UnsupportedVersion::STATUS);
        $response->assertJsonPath(UnsupportedVersion::KEY.'.requested', null);
    }

    public function test_the_unsupported_outcome_is_none_of_the_four_error_branches(): void
    {
        // §8.6 places it outside the four: "Interface version unsupported — its
        // own outcome". A client matching on the four keys must not match it.
        $body = $this->getJson('/api/v9/versions')->json();

        self::assertIsArray($body);

        foreach (['invalid_request', 'refusal', 'unavailable', 'fault'] as $branch) {
            self::assertArrayNotHasKey($branch, $body);
        }

        self::assertArrayHasKey(UnsupportedVersion::KEY, $body);
    }
}
