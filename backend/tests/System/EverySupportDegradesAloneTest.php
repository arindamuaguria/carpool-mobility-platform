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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * `NFR-034` ‡ — *"The system shall continue to operate in its defined degraded
 * mode when **any single** supporting service is unavailable."*
 *
 * **Level 5** (`TC-025`): the platform's own capability register, the real
 * `DatabasePolicyStore`, a real MySQL and a real HTTP request. Nothing below this
 * level can say what the deployed composition does, and the statement is about the
 * deployed composition.
 *
 * ## Why this was worth writing
 *
 * `PlatformHealthTest` proves what happens when **every** declared value is unset:
 * health still answers, and reports that nothing is available. That is a real
 * property and it is not this one. With everything unset there is nothing left to
 * continue operating, so the assertion cannot distinguish *"continues in its
 * degraded mode"* from *"withdraws the platform entirely"* — and the second is
 * what `NFR-034` ‡ is written to forbid.
 *
 * The statement's content is **partial** failure: one thing is gone, the rest keeps
 * working. So each support is made unavailable **alone**, every other value being
 * set, and the capabilities that do not need it are required to remain available.
 * Today that is a genuine discrimination: `session` needs two of the five values
 * and `authentication.material` needs the other three, so each case has something
 * to keep working.
 *
 * ## Derived, not listed
 *
 * The cases come from {@see DegradationServiceProvider::declaredCapabilities()},
 * so a support added on the commit that builds its capability arrives here with a
 * case already written. A list of five names would have been a list that stopped
 * being the register's.
 *
 * `FRD-FR-256` ‡ is the other half and is asserted in the same pass: an affected
 * capability is never reported as working.
 */
final class EverySupportDegradesAloneTest extends TestCase
{
    use ClearsTheEvidentialLog;

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

    /**
     * Every support the register declares, one case each.
     *
     * @return iterable<string, array{string}>
     */
    public static function declaredSupports(): iterable
    {
        foreach (DegradationServiceProvider::declaredCapabilities()->supports() as $support) {
            yield $support->name() => [$support->name()];
        }
    }

    #[DataProvider('declaredSupports')]
    public function test_the_platform_continues_when_this_support_alone_is_unavailable(string $support): void
    {
        $this->applyEveryValueExcept($support);

        $response = $this->getJson('/api/v1/health');

        // NFR-034 ‡, the "continue to operate" half at its narrowest: the platform
        // answers, and says of itself that it is answering. BE-203 keeps that
        // distinct from what its dependencies are doing.
        $response->assertOk();
        $response->assertJsonPath('data.platform.answering', true);

        $data = $this->health();

        // FRD-FR-257: the one thing that is missing, named, and named as the kind
        // it is — and nothing else named with it.
        self::assertSame(
            [['kind' => Kind::PolicyValue->value, 'name' => $support]],
            $data['missing'],
            'FRD-FR-257: exactly what is unavailable, and nothing that is not.',
        );

        self::assertFalse($data['fully_available']);

        $unaffected = self::capabilitiesNotNeeding($support);
        $affected = self::capabilitiesNeeding($support);

        // The statement itself. A capability that does not depend on the missing
        // support is still offered — which is what "continue to operate in its
        // defined degraded mode" means and what unsetting everything cannot show.
        foreach ($unaffected as $name) {
            self::assertSame(
                CapabilityStanding::Available->value,
                $data['capabilities'][$name] ?? null,
                'NFR-034 ‡: "'.$name.'" does not depend on "'.$support.'", so it continues to be offered while '
                .'that one support is unavailable. A platform that withdrew everything would not be operating in '
                .'a degraded mode; it would be down.',
            );
        }

        // FRD-FR-256 ‡, the other direction: and the ones that do depend on it are
        // never reported as working.
        foreach ($affected as $name) {
            self::assertNotSame(
                CapabilityStanding::Available->value,
                $data['capabilities'][$name] ?? null,
                'FRD-FR-256 ‡: "'.$name.'" needs "'.$support.'", so it is withdrawn or marked and not presented '
                .'as working.',
            );
        }

        // A case in which nothing is affected would assert nothing about
        // degradation, and a case in which everything is affected would assert
        // nothing about continuing.
        self::assertNotSame([], $affected, 'A support no capability needs has no business in the register.');
        self::assertNotSame(
            [],
            $unaffected,
            'Every declared capability needs "'.$support.'", so this case cannot show the platform continuing. '
            .'NFR-034 ‡ would then be unobservable at this level and the reason must be recorded, not passed over.',
        );
    }

    /**
     * `TC-041` — the induction is valid for every support the register declares,
     * and says so rather than skipping what it cannot do.
     *
     * Two things could quietly hollow this out. A support of {@see Kind::Service}
     * cannot be made unavailable by leaving a policy value unset, so a case for one
     * would set every value, observe nothing missing and pass. And a declared value
     * that is not any capability's support would be set by
     * {@see applyEveryValueExcept()} without ever being a case.
     */
    public function test_the_induction_covers_every_support_the_register_declares(): void
    {
        $kinds = [];

        foreach (DegradationServiceProvider::declaredCapabilities()->supports() as $support) {
            $kinds[$support->name()] = $support->kind();
        }

        foreach ($kinds as $name => $kind) {
            self::assertSame(
                Kind::PolicyValue,
                $kind,
                '"'.$name.'" is a '.$kind->value.' support, and leaving a policy value unset does not make it '
                .'unavailable. This test induces one kind of unavailability; a service needs its own induction '
                .'(a double for ObservesSupport, or a failing adapter) and this class must gain it rather than '
                .'report a case it did not exercise.',
            );
        }

        $supports = array_keys($kinds);
        $values = array_keys(self::values());

        sort($supports);
        sort($values);

        self::assertSame(
            $supports,
            $values,
            'Every value this test knows how to set is a support, and every support is a value it can set. A '
            .'mismatch either leaves a support with no case or sets something the register never asked for.',
        );
    }

    /**
     * Every declared value, and a figure CMP-DOC-13 records for it.
     *
     * The five `PlatformHealthTest` also sets. The **keys** come from the classes
     * that read them, because `DB-153` ‡ declares a policy key in exactly one place
     * and a second spelling here would be a second declaration. What is stated
     * independently is the **set** — which is the thing
     * {@see test_the_induction_covers_every_support_the_register_declares()} checks
     * against the register.
     *
     * @return array<string, string>
     */
    private static function values(): array
    {
        return [
            ResolveSession::LIFETIME_KEY => '86400',
            PolicyServiceProvider::concurrentSessionLimit()->name() => '3',
            Argon2idAuthenticationMaterial::MEMORY_KEY => '19456',
            Argon2idAuthenticationMaterial::ITERATIONS_KEY => '2',
            Argon2idAuthenticationMaterial::LANES_KEY => '1',
        ];
    }

    /**
     * @return list<string>
     */
    private static function capabilitiesNeeding(string $support): array
    {
        $names = [];

        foreach (DegradationServiceProvider::capabilities() as $dependence) {
            foreach ($dependence->needs() as $need) {
                if ($need->name() === $support) {
                    $names[] = $dependence->capability()->name();

                    break;
                }
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function capabilitiesNotNeeding(string $support): array
    {
        $needing = self::capabilitiesNeeding($support);
        $names = [];

        foreach (DegradationServiceProvider::capabilities() as $dependence) {
            if (! in_array($dependence->capability()->name(), $needing, true)) {
                $names[] = $dependence->capability()->name();
            }
        }

        return $names;
    }

    private function applyEveryValueExcept(string $support): void
    {
        foreach (self::values() as $key => $value) {
            if ($key === $support) {
                continue;
            }

            $this->app->make(ChangePolicyValue::class)->apply($key, $value, 'operator-under-test');
        }
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

    private function clearValues(): void
    {
        $migration = $this->connection('mysql_migration');
        $store = $this->app->make(DatabasePolicyStore::class);

        foreach (array_keys(self::values()) as $name) {
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

        // BE-173 evidences every policy change, and applyEveryValueExcept() made
        // four of them.
        $this->clearEvidentialLog();
    }

    private function connection(string $name): Connection
    {
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection($name);

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
