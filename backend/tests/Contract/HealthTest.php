<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Domain\Shared\Degradation\CapabilityStanding;
use Cmp\Domain\Shared\Degradation\Kind;
use Cmp\Interface\Rest\ServedVersions;
use Tests\TestCase;

/**
 * `BE-203` / CMP-DOC-10 §11.13 — the shape of the health indication.
 *
 * **Level 4** (`TC-031`): *shape, never business behaviour.* What the standings
 * actually are depends on what the platform's own register declares and on what
 * is configured, which is level 5's — in `Tests\System\PlatformHealthTest`. What
 * is asserted here is that the response has the shape a client can be written
 * against, and that it says nothing it must not.
 */
final class HealthTest extends TestCase
{
    public function test_health_is_reachable_without_a_session(): void
    {
        // §9.1 names platform health among the five, citing BE-203. That
        // placement is the point: a client that cannot authenticate because the
        // capability that authenticates is withdrawn must still learn why. No
        // credential and no idempotency key is sent here.
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_the_body_distinguishes_the_platform_from_its_dependencies(): void
    {
        // BE-203, in terms: "health indication distinguishing platform from
        // dependencies". A single green-or-red light answers neither question an
        // operator has.
        $response = $this->getJson('/api/v1/health');

        $response->assertJsonStructure([
            'meta' => ['interface_version', 'deprecated', 'evaluated_at'],
            'data' => ['platform' => ['answering'], 'capabilities', 'missing', 'fully_available'],
        ]);
        $response->assertJsonPath('data.platform.answering', true);
    }

    public function test_every_capability_carries_a_standing_from_the_declared_set(): void
    {
        // FRD-FR-256 ‡ has three standings and no fourth. A client switches on
        // this value, so an unrecognised one is a client that does not know what
        // to do — and SRS-REQ-113 forbids the platform inventing one.
        $capabilities = $this->getJson('/api/v1/health')->json('data.capabilities');

        self::assertIsArray($capabilities);
        self::assertNotSame([], $capabilities, 'The platform declares at least one capability.');

        $permitted = array_column(CapabilityStanding::cases(), 'value');

        foreach ($capabilities as $name => $standing) {
            self::assertIsString($name);
            self::assertContains($standing, $permitted);
        }
    }

    public function test_what_remains_available_is_readable_from_the_same_list(): void
    {
        // FRD-FR-257 / API-091: "what is unavailable and what remains available".
        // Both halves come from `capabilities`, so a client never has to know the
        // full set separately in order to subtract.
        $data = $this->getJson('/api/v1/health')->json('data');

        self::assertIsArray($data);
        self::assertArrayHasKey('capabilities', $data);
        self::assertIsArray($data['capabilities']);

        $available = array_filter(
            $data['capabilities'],
            static fn (mixed $standing): bool => $standing === CapabilityStanding::Available->value,
        );

        self::assertSame($data['fully_available'], count($available) === count($data['capabilities']));
    }

    public function test_each_missing_dependency_states_its_kind_and_its_name(): void
    {
        // FRD-FR-257 tells the actor what is unavailable. The kind matters to
        // whoever reads it: a service that is not answering may answer in a
        // minute, and a value nobody has configured will not set itself.
        $missing = $this->getJson('/api/v1/health')->json('data.missing');

        self::assertIsArray($missing);

        $kinds = array_column(Kind::cases(), 'value');

        foreach ($missing as $entry) {
            self::assertIsArray($entry);
            self::assertSame(['kind', 'name'], array_keys($entry));
            self::assertContains($entry['kind'], $kinds);
            self::assertIsString($entry['name']);
        }
    }

    public function test_the_body_names_no_provider_class_table_or_diagnostic(): void
    {
        // API-090's reasoning at a surface anyone can reach. The body carries what
        // a client needs in order to behave correctly and nothing that would help
        // somebody attack the platform — no hostname, no version, no stack, no
        // query, no queue depth (SEC-114, OPS-098, API-092 ‡).
        $body = (string) $this->getJson('/api/v1/health')->getContent();

        foreach (['Cmp\\', 'Illuminate', 'mysql', 'op_', 'Exception', 'vendor/', 'localhost'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_health_carries_the_envelope_every_response_carries(): void
    {
        // API-022 / API-043 ‡. Health is not a special case of the surface; it is
        // an operation on it.
        $response = $this->getJson('/api/v1/health');

        $response->assertJsonPath('meta.interface_version', ServedVersions::CURRENT);
        $response->assertJsonPath('meta.deprecated', false);
    }

    public function test_an_unsupported_version_is_refused_before_health_answers(): void
    {
        // API-024 ‡: the version check is a property of the surface, and being
        // in §9.1 does not exempt an operation from it. A client on an
        // unsupported version gets the version outcome rather than a health
        // report it would then misread.
        $this->getJson('/api/v9/health')->assertStatus(426);
    }
}
