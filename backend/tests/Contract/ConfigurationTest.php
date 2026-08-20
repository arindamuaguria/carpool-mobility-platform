<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Application\Shared\Configuration\DeliveredValue;
use Cmp\Application\Shared\Configuration\ServeConfiguration;
use Cmp\Domain\Shared\Refusal\RefusalReason;
use Cmp\Infrastructure\Laravel\Providers\ConfigurationServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\User\Argon2idAuthenticationMaterial;
use Tests\TestCase;

/**
 * CMP-DOC-10 §14 — the shape of the configuration resource.
 *
 * **Level 4** (`TC-031`): *shape, never business behaviour.* What the values
 * **are** depends on what an operator has applied, which is level 5's in
 * `Tests\System\ConfigurationDeliveryTest`. What is asserted here is the shape a
 * client is written against, and the two things the response must never do.
 */
final class ConfigurationTest extends TestCase
{
    public function test_the_public_subset_is_reachable_without_a_session(): void
    {
        // §9.1: "a cold client needs conservative defaults" — and needs to
        // replace them before it can do anything else, including authenticate.
        $this->getJson('/api/v1/configuration')->assertOk();
    }

    public function test_the_response_carries_values_and_their_versions(): void
    {
        // API-187 ‡ and BE-167. One response-level version for API-189's change
        // detection, and per-value versions so a decision can record the version
        // it used.
        $response = $this->getJson('/api/v1/configuration');

        $response->assertJsonStructure([
            'meta' => ['interface_version', 'evaluated_at', 'configuration_version'],
            'data' => ['values', 'versions'],
        ]);
    }

    public function test_the_refusal_reason_set_is_delivered_with_a_default_for_each(): void
    {
        // AADR-14: the client presents its own localised text keyed by the
        // identifier and falls back to the default for one it does not recognise
        // — so it needs both, and it needs them before the first refusal arrives.
        $reasons = $this->getJson('/api/v1/configuration')
            ->json('data.values.'.ServeConfiguration::REFUSAL_REASONS);

        self::assertIsArray($reasons);
        self::assertNotSame([], $reasons);

        foreach ($reasons as $entry) {
            self::assertIsArray($entry);
            self::assertSame(['reason', 'default_text'], array_keys($entry));
            self::assertNotSame('', $entry['default_text']);
        }

        // Every **identifier** the platform can return, which
        // ConfigurationRulesTest keeps in step with the code at level 1. Counted
        // as distinct identifiers rather than as reason cases, because
        // StateMachineRefusal deliberately gives two of its three cases one
        // identifier — API-086 ‡ and API-094 ‡ require the two to be
        // indistinguishable to a caller, and the set a client keys its text by
        // is the identifiers.
        $declared = array_unique(array_map(
            static fn (RefusalReason $reason): string => $reason->identifier(),
            ConfigurationServiceProvider::refusalReasons(),
        ));

        self::assertCount(count($declared), $reasons);
    }

    public function test_an_unset_value_is_absent_rather_than_null(): void
    {
        // API-193: the client holds a conservative default "applied only until
        // its first successful fetch", and a null it cannot type is worse than
        // an absence it can recognise. API-192 requires every value served to be
        // typed, which null is not.
        $values = $this->getJson('/api/v1/configuration')->json('data.values');

        self::assertIsArray($values);

        foreach ($values as $name => $value) {
            self::assertNotNull($value, $name.': API-193 omits an unset value; it does not serve null.');
        }
    }

    public function test_the_public_subset_discloses_no_platform_state(): void
    {
        // API-195. The concurrent-session limit and the three Argon2id costs are
        // policy values the platform holds and §14.2 does not list — how many
        // sessions a user may hold and how expensive a hash is are facts about
        // the platform that no client needs in order to behave correctly.
        $response = $this->getJson('/api/v1/configuration');
        $values = $response->json('data.values');

        self::assertIsArray($values);

        // Asserted on the **set of keys**, not on substrings of the body. The
        // policy key `session.concurrent_limit` is a prefix of the refusal
        // identifier `session.concurrent_limit_reached`, and a substring check
        // cannot tell the two apart — a refusal may name a limit without
        // disclosing what it is, which is precisely the distinction API-195
        // draws. The set of keys can tell them apart, and does.
        $public = array_map(
            static fn (DeliveredValue $value): string => $value->name(),
            array_filter(
                ConfigurationServiceProvider::delivered(),
                static fn (DeliveredValue $value): bool => $value->isPublic(),
            ),
        );

        // A **subset**, not the whole set: API-193 omits a value the operator has
        // not applied, so what is served is bounded above by what is declared
        // public and below by what is currently set. What matters for API-195 is
        // that nothing outside the declared public set ever appears.
        foreach (array_keys($values) as $served) {
            self::assertContains($served, $public, $served.' is served and is not a declared public value.');
        }

        // The undelivered policy values do not appear as keys, which is what
        // "discloses no platform state" means for this response: how many
        // sessions a user may hold and how expensive a hash is are facts about
        // the platform's configuration, not values a client needs.
        foreach ([
            PolicyServiceProvider::concurrentSessionLimit()->name(),
            Argon2idAuthenticationMaterial::MEMORY_KEY,
            Argon2idAuthenticationMaterial::ITERATIONS_KEY,
            Argon2idAuthenticationMaterial::LANES_KEY,
        ] as $withheld) {
            self::assertArrayNotHasKey($withheld, $values);
        }

        // And nothing internal leaks in the body at all.
        $body = (string) $response->getContent();

        foreach (['Cmp\\', 'Illuminate', 'mysql', 'op_', 'localhost'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_every_response_on_the_surface_carries_the_configuration_version(): void
    {
        // API-189: "any response on any surface may indicate that configuration
        // has changed, so that the client refetches without polling" — which is
        // what makes API-190 ‡'s prohibition on polling something a client can
        // obey. Asserted on a response that has nothing to do with configuration.
        foreach (['/api/v1/versions', '/api/v1/health', '/api/v1/configuration'] as $path) {
            $this->getJson($path)->assertJsonStructure(['meta' => ['configuration_version']]);
        }
    }

    public function test_the_version_is_stable_across_two_identical_fetches(): void
    {
        // A version that moved when nothing had would tell a client to refetch
        // for nothing, and API-190 ‡ leaves it no other way to decide.
        $first = $this->getJson('/api/v1/configuration')->json('meta.configuration_version');
        $second = $this->getJson('/api/v1/configuration')->json('meta.configuration_version');

        self::assertIsString($first);
        self::assertSame($first, $second);
    }

    public function test_the_version_is_opaque_and_encodes_no_position_a_client_could_manipulate(): void
    {
        // API-013's reasoning, applied to a marking rather than a cursor: a
        // client compares it and does nothing else with it, so it must not look
        // like a number it could increment.
        $version = $this->getJson('/api/v1/configuration')->json('meta.configuration_version');

        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $version);
    }
}
