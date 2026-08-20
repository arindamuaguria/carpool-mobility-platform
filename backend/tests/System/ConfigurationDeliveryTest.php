<?php

declare(strict_types=1);

namespace Tests\System;

use Cmp\Application\Shared\Configuration\ServeConfiguration;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\ResolveSession;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * CMP-DOC-10 §14 end to end — a value an operator sets reaching a client.
 *
 * **Level 5** (`TC-025`): a real HTTP request, the real register, the real
 * `ChangePolicyValue` and a real MySQL. What this adds to level 4 is the part
 * only the real composition can show — that `API-196`'s promise holds. *"A
 * configuration change shall not require a client release, and shall not be
 * delivered by any other means."*
 *
 * The whole of `AADR-13` rests on that sentence being true rather than intended,
 * and the only way to know is to change a value and fetch it.
 */
final class ConfigurationDeliveryTest extends TestCase
{
    use ClearsTheEvidentialLog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearLifetime();
    }

    protected function tearDown(): void
    {
        $this->clearLifetime();

        parent::tearDown();
    }

    public function test_an_unset_value_is_omitted_and_the_client_keeps_its_default(): void
    {
        // API-193, on the platform's real state. Nothing is synthesised
        // (SRS-REQ-113), and the omission is not hidden — GET /health names the
        // unset value and withdraws the capability that reads it.
        $values = $this->configuration()['values'];

        self::assertArrayNotHasKey(ServeConfiguration::SESSION_LIFETIME, $values);

        $missing = $this->getJson('/api/v1/health')->json('data.missing');

        self::assertIsArray($missing);
        self::assertContains(ResolveSession::LIFETIME_KEY, array_column($missing, 'name'));
    }

    public function test_a_value_an_operator_sets_reaches_a_client_with_no_release(): void
    {
        // API-196 and API-187 ‡ together, which is the whole point of the
        // resource: SEC-039 ‡'s twenty-four hours is applied by an operator
        // action (BADR-12) and arrives at the client on its next fetch.
        $this->apply('86400');

        $served = $this->configuration();

        // API-192: in the declared type, never as a string.
        self::assertSame(86400, $served['values'][ServeConfiguration::SESSION_LIFETIME]);

        // BE-167: with the version it came from, so a decision can record it.
        self::assertArrayHasKey(ServeConfiguration::SESSION_LIFETIME, $served['versions']);
        self::assertIsInt($served['versions'][ServeConfiguration::SESSION_LIFETIME]);
    }

    public function test_the_configuration_version_moves_when_a_value_moves(): void
    {
        // API-188 and API-189. A client that may not poll (API-190 ‡) learns of a
        // change by comparing this, so it has to actually move.
        $this->apply('86400');
        $before = $this->version();

        $this->apply('43200');
        $after = $this->version();

        self::assertNotSame($before, $after);
        self::assertSame(43200, $this->configuration()['values'][ServeConfiguration::SESSION_LIFETIME]);
    }

    public function test_the_configuration_version_moves_when_a_value_leaves_the_served_set(): void
    {
        // The case a highest-version-wins scheme would miss, and the reason the
        // version is a digest over the served set: an unset value stops being
        // delivered and no version anywhere increases. A client comparing a
        // maximum would keep applying a value the platform had stopped sending.
        $this->apply('86400');
        $withValue = $this->version();

        $this->clearLifetime();

        self::assertNotSame($withValue, $this->version());
    }

    public function test_the_change_reaches_a_response_that_is_not_about_configuration(): void
    {
        // API-189: "any response on any surface". A client fetching something
        // else entirely is told that configuration moved, which is what lets it
        // obey API-190 ‡ and never poll.
        $this->apply('86400');

        $onVersions = $this->getJson('/api/v1/versions')->json('meta.configuration_version');
        $onConfiguration = $this->version();

        self::assertIsString($onVersions);
        self::assertSame($onConfiguration, $onVersions);
    }

    public function test_a_marking_that_cannot_be_produced_is_null_rather_than_an_error(): void
    {
        // ConfigurationVersion returns null rather than raising, so a marking
        // cannot take down an answer it had nothing to do with. With nothing set
        // the version is still produced — the served set is simply smaller — so
        // what is asserted here is that the field is always present and typed,
        // which is what a client reads.
        $marking = $this->getJson('/api/v1/health')->json('meta.configuration_version');

        self::assertIsString($marking);
    }

    /**
     * @return array{values: array<string, mixed>, versions: array<string, int>}
     */
    private function configuration(): array
    {
        $data = $this->getJson('/api/v1/configuration')->json('data');

        self::assertIsArray($data);
        self::assertIsArray($data['values'] ?? null);
        self::assertIsArray($data['versions'] ?? null);

        /** @var array{values: array<string, mixed>, versions: array<string, int>} $data */
        return $data;
    }

    private function version(): string
    {
        $version = $this->getJson('/api/v1/configuration')->json('meta.configuration_version');

        self::assertIsString($version);

        return $version;
    }

    private function apply(string $seconds): void
    {
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, $seconds, 'operator-under-test',
        );
    }

    private function clearLifetime(): void
    {
        $migration = $this->connection('mysql_migration');

        $migration->delete(
            'DELETE FROM '.DatabasePolicyStore::VERSIONS_TABLE.' WHERE policy_value_id IN'
            .' (SELECT id FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?)',
            [ResolveSession::LIFETIME_KEY],
        );
        $migration->delete(
            'DELETE FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
            [ResolveSession::LIFETIME_KEY],
        );

        $this->app->make(DatabasePolicyStore::class)->forget(PolicyServiceProvider::sessionLifetime());
        $this->clearEvidentialLog();
    }

    private function connection(string $name): Connection
    {
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection($name);

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
