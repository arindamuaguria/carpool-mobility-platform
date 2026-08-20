<?php

declare(strict_types=1);

namespace Tests\System;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Degradation\CapabilityStanding;
use Cmp\Domain\Shared\Degradation\Kind;
use Cmp\Infrastructure\Laravel\Providers\DegradationServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use Cmp\Infrastructure\User\Argon2idAuthenticationMaterial;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * `UC-081` end to end — the platform reporting its own degraded mode.
 *
 * **Level 5** (`TC-025`): a real HTTP request, the platform's **own** capability
 * register, the real `DatabasePolicyStore` and a real MySQL. What this adds to
 * levels 2 and 4 is the part only the real composition can show — that the
 * platform declares the capabilities it actually offers, that an unset policy
 * value really does withdraw one, and that setting it really does bring it back
 * with no restart.
 *
 * That last point is `FRD-FR-260`, and it is the one this level exists for: at
 * level 2 the observer is a double whose answer the test changes, and here the
 * answer changes because an operator applied a policy value through
 * `ChangePolicyValue` — the same act `BADR-12` describes and `BE-173` evidences.
 */
final class PlatformHealthTest extends TestCase
{
    use ClearsTheEvidentialLog;

    private const SESSION = 'session';

    private const MATERIAL = 'authentication.material';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearValues();
    }

    protected function tearDown(): void
    {
        $this->clearValues();

        parent::tearDown();
    }

    public function test_the_platform_declares_the_capabilities_it_actually_offers(): void
    {
        // The register's own rule: an entry is added on the commit that builds
        // the capability it describes. Two operations exist that a client can
        // call and that depend on configuration, and the register holds two.
        $capabilities = $this->health()['capabilities'];

        self::assertSame([self::SESSION, self::MATERIAL], array_keys($capabilities));
    }

    public function test_a_capability_whose_policy_value_is_unset_is_withdrawn(): void
    {
        // FRD-FR-256 ‡ over the wire, on the platform's real state. SEC-031
        // leaves the three Argon2id costs deployment-time and BAD-DEP-009 has
        // selected no hardware, so nothing hashes — and a health indication
        // reporting the capability as available would be presenting it as
        // working, which is the thing the requirement forbids.
        $data = $this->health();

        self::assertSame(CapabilityStanding::Withdrawn->value, $data['capabilities'][self::MATERIAL]);
        self::assertSame(CapabilityStanding::Withdrawn->value, $data['capabilities'][self::SESSION]);
        self::assertFalse($data['fully_available']);
    }

    public function test_every_unset_value_is_named_and_not_only_the_first(): void
    {
        // FRD-FR-257: an operator told about one of five sets one and comes back.
        $named = array_column($this->health()['missing'], 'name');

        sort($named);

        $expected = [
            Argon2idAuthenticationMaterial::ITERATIONS_KEY,
            Argon2idAuthenticationMaterial::LANES_KEY,
            Argon2idAuthenticationMaterial::MEMORY_KEY,
            PolicyServiceProvider::concurrentSessionLimit()->name(),
            ResolveSession::LIFETIME_KEY,
        ];

        sort($expected);

        self::assertSame($expected, $named);

        // And each is reported as the kind it is — a value nobody has configured
        // will not set itself, which is a different thing to tell an operator
        // than a service that is not answering.
        foreach ($this->health()['missing'] as $entry) {
            self::assertSame(Kind::PolicyValue->value, $entry['kind']);
        }
    }

    public function test_setting_a_value_restores_its_capability_with_no_restart(): void
    {
        // FRD-FR-260, step 5 of UC-081, through the real store. Nothing is reset,
        // nothing is restarted, and BE-170's cache invalidation is what carries
        // it — a latch nobody clears is how this requirement is usually failed.
        self::assertSame(CapabilityStanding::Withdrawn->value, $this->health()['capabilities'][self::MATERIAL]);

        $this->apply(Argon2idAuthenticationMaterial::MEMORY_KEY, '19456');
        $this->apply(Argon2idAuthenticationMaterial::ITERATIONS_KEY, '2');
        $this->apply(Argon2idAuthenticationMaterial::LANES_KEY, '1');

        $data = $this->health();

        self::assertSame(CapabilityStanding::Available->value, $data['capabilities'][self::MATERIAL]);

        // And the other capability is untouched: FRD-FR-255 determines which
        // capabilities are affected, not which are mentioned nearby.
        self::assertSame(CapabilityStanding::Withdrawn->value, $data['capabilities'][self::SESSION]);
        self::assertFalse($data['fully_available']);
    }

    public function test_the_platform_reports_fully_available_only_when_everything_is_set(): void
    {
        foreach ($this->keys() as $key => $value) {
            $this->apply($key, $value);
        }

        $data = $this->health();

        self::assertTrue($data['fully_available']);
        self::assertSame([], $data['missing']);
        self::assertSame(
            [self::SESSION => CapabilityStanding::Available->value, self::MATERIAL => CapabilityStanding::Available->value],
            $data['capabilities'],
        );
    }

    public function test_a_withdrawn_capability_is_withdrawn_and_never_merely_marked(): void
    {
        // FRD-FR-259 ‡ on the platform's own register: both capabilities are
        // declared essential, because a session with a guessed lifetime has no
        // bound (SEC-039 ‡) and a credential hashed at a guessed cost compromises
        // SEC-028 ‡. Neither has a reduced form, so Marked must never appear.
        $capabilities = $this->health()['capabilities'];

        self::assertNotContains(CapabilityStanding::Marked->value, $capabilities);

        foreach (DegradationServiceProvider::capabilities() as $dependence) {
            self::assertTrue(
                $dependence->capability()->isEssential(),
                $dependence->capability()->name().': FRD-FR-259 ‡ permits only withdrawal for it.',
            );
        }
    }

    public function test_health_answers_while_the_platform_is_degraded(): void
    {
        // The property that makes the endpoint worth having. Every declared value
        // is unset, so session resolution raises and nothing hashes — and health
        // still answers 200 and says so. An endpoint that failed because the
        // platform was unhealthy would be the least useful object in the system.
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $response->assertJsonPath('data.platform.answering', true);
        $response->assertJsonPath('data.fully_available', false);
    }

    /**
     * @return array{capabilities: array<string, string>, missing: list<array{kind: string, name: string}>, fully_available: bool}
     */
    private function health(): array
    {
        $data = $this->getJson('/api/v1/health')->json('data');

        self::assertIsArray($data);
        self::assertIsArray($data['capabilities'] ?? null);
        self::assertIsArray($data['missing'] ?? null);
        self::assertIsBool($data['fully_available'] ?? null);

        /** @var array{capabilities: array<string, string>, missing: list<array{kind: string, name: string}>, fully_available: bool} $data */
        return $data;
    }

    /**
     * Every declared value, and a figure for it that CMP-DOC-13 records.
     *
     * @return array<string, string>
     */
    private function keys(): array
    {
        return [
            ResolveSession::LIFETIME_KEY => '86400',
            PolicyServiceProvider::concurrentSessionLimit()->name() => '3',
            Argon2idAuthenticationMaterial::MEMORY_KEY => '19456',
            Argon2idAuthenticationMaterial::ITERATIONS_KEY => '2',
            Argon2idAuthenticationMaterial::LANES_KEY => '1',
        ];
    }

    private function apply(string $key, string $value): void
    {
        $this->app->make(ChangePolicyValue::class)->apply($key, $value, 'operator-under-test');
    }

    private function clearValues(): void
    {
        $migration = $this->connection('mysql_migration');
        $store = $this->app->make(DatabasePolicyStore::class);

        foreach (array_keys($this->keys()) as $name) {
            $migration->delete(
                'DELETE FROM '.DatabasePolicyStore::VERSIONS_TABLE.' WHERE policy_value_id IN'
                .' (SELECT id FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?)',
                [$name],
            );
            $migration->delete(
                'DELETE FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
                [$name],
            );

            $store->forget(PolicyServiceProvider::declaredValues()->key($name));
        }

        // BE-173 evidences every policy change, and apply() made some.
        $this->clearEvidentialLog();
    }

    private function connection(string $name): Connection
    {
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection($name);

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
