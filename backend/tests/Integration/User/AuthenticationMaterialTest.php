<?php

declare(strict_types=1);

namespace Tests\Integration\User;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashCostBelowFloor;
use Cmp\Application\User\HashesAuthenticationMaterial;
use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyNotSet;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use Cmp\Infrastructure\User\Argon2idAuthenticationMaterial;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\Integration\IntegrationTestCase;

/**
 * `SEC-244` ‡ — the floor holding through the real policy store.
 *
 * **Level 3** (`TC-030` ‡): a real MySQL, because what this adds to level 2 is
 * that the three costs are declared in the **platform's** register, arrive
 * through `DatabasePolicyStore`, and are refused when an operator applies one
 * beneath the floor. The construction itself is level 2's, in
 * `Tests\Domain\User\Argon2idAuthenticationMaterialTest`.
 *
 * The distinction matters because the level-2 tests hold their own register. A
 * floor that worked against a test's register and not against the platform's
 * would pass every one of them.
 */
final class AuthenticationMaterialTest extends IntegrationTestCase
{
    use ClearsTheEvidentialLog;

    private const MATERIAL = '481920';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearCosts();
    }

    protected function tearDown(): void
    {
        $this->clearCosts();

        parent::tearDown();
    }

    public function test_the_platform_declares_the_three_costs(): void
    {
        // SEC-030: the costs are configuration, tunable without a code change.
        // DB-153 ‡ makes the register the mechanism, so being in it is what makes
        // them settable at all.
        $register = PolicyServiceProvider::declaredValues();

        foreach ($this->keys() as $key) {
            self::assertTrue(
                $register->declares($key->name()),
                'SEC-030: '.$key->name().' is read on every hash, so it is declared.',
            );
        }
    }

    public function test_the_costs_ship_unset_and_nothing_hashes_until_an_operator_sets_them(): void
    {
        // SEC-031: the operating values are deployment-time, set against the
        // deployed hardware — and BAD-DEP-009 has selected no hosting, so there
        // is no hardware to set them against. SRS-REQ-158 rejects the attempt
        // rather than falling back to a figure nobody chose.
        $this->expectException(PolicyNotSet::class);

        $this->app->make(HashesAuthenticationMaterial::class)->hash(self::MATERIAL);
    }

    public function test_an_operator_setting_the_costs_makes_the_platform_hash(): void
    {
        // BADR-12 / BE-173: applied by an operator action, evidenced. Set at the
        // floor, which is the cheapest configuration the platform will run at.
        $this->apply(
            Argon2idAuthenticationMaterial::FLOOR_MEMORY_KIB,
            Argon2idAuthenticationMaterial::FLOOR_ITERATIONS,
            Argon2idAuthenticationMaterial::FLOOR_LANES,
        );

        $hasher = $this->app->make(HashesAuthenticationMaterial::class);
        $stored = $hasher->hash(self::MATERIAL);

        self::assertStringStartsWith('$argon2id$', $stored);
        self::assertTrue($hasher->matches(self::MATERIAL, $stored));
        self::assertFalse($hasher->matches('481921', $stored));
    }

    public function test_a_cost_set_beneath_the_floor_is_refused_by_the_platform(): void
    {
        // The negative test FR-04 requires of SEC-244 ‡, at the level where it
        // could actually happen: an operator with the authority to change a
        // policy value sets one too low, and the platform refuses to hash rather
        // than hashing weakly.
        //
        // BE-172 ‡ holds in exactly this direction. The value was accepted by
        // ChangePolicyValue — it is a valid integer for a declared key — and the
        // refusal comes from the reader, because a floor is not a type.
        $this->apply(
            1024,
            Argon2idAuthenticationMaterial::FLOOR_ITERATIONS,
            Argon2idAuthenticationMaterial::FLOOR_LANES,
        );

        $this->expectException(HashCostBelowFloor::class);
        $this->expectExceptionMessageMatches('/memory 1024 KiB is below 19456 KiB/');

        $this->app->make(HashesAuthenticationMaterial::class)->hash(self::MATERIAL);
    }

    public function test_raising_a_cost_takes_effect_without_a_restart(): void
    {
        // BE-170: the cache is invalidated on change and no restart is required.
        // SEC-030 requires the parameters to be re-tuned when hardware changes,
        // and a re-tuning that needed a deployment would be a deployment
        // configuration — which BE-171 says these are not.
        $this->apply(
            Argon2idAuthenticationMaterial::FLOOR_MEMORY_KIB,
            Argon2idAuthenticationMaterial::FLOOR_ITERATIONS,
            Argon2idAuthenticationMaterial::FLOOR_LANES,
        );

        $hasher = $this->app->make(HashesAuthenticationMaterial::class);
        $stored = $hasher->hash(self::MATERIAL);

        self::assertFalse($hasher->needsRehash($stored));

        $this->apply(
            Argon2idAuthenticationMaterial::FLOOR_MEMORY_KIB,
            Argon2idAuthenticationMaterial::FLOOR_ITERATIONS + 1,
            Argon2idAuthenticationMaterial::FLOOR_LANES,
        );

        // SEC-032 ‡: the same object, no restart, and the value stored a moment
        // ago is now below the current setting.
        self::assertTrue($hasher->needsRehash($stored));
    }

    /**
     * @return list<PolicyKey>
     */
    private function keys(): array
    {
        return PolicyServiceProvider::authenticationHashCost();
    }

    private function apply(int $memory, int $iterations, int $lanes): void
    {
        $operator = $this->app->make(ChangePolicyValue::class);
        $values = [$memory, $iterations, $lanes];

        foreach ($this->keys() as $index => $key) {
            $operator->apply($key->name(), (string) $values[$index], 'operator-under-test');
        }
    }

    private function clearCosts(): void
    {
        $migration = $this->migrationConnection();
        $store = $this->app->make(DatabasePolicyStore::class);

        foreach ($this->keys() as $key) {
            $migration->delete(
                'DELETE FROM '.DatabasePolicyStore::VERSIONS_TABLE.' WHERE policy_value_id IN'
                .' (SELECT id FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?)',
                [$key->name()],
            );
            $migration->delete(
                'DELETE FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
                [$key->name()],
            );

            $store->forget($key);
        }

        // BE-173 evidences every policy change, and each apply() above wrote a
        // record. The log goes back to empty so that a test asserting what is in
        // it is not reading another test's work.
        $this->clearEvidentialLog();
    }

    /**
     * Guards the assumption the helpers rest on: the three keys come back in the
     * order {@see apply()} assigns values to them.
     */
    public function test_the_three_costs_are_declared_in_memory_iteration_lane_order(): void
    {
        $names = array_map(static fn (PolicyKey $key): string => $key->name(), $this->keys());

        self::assertSame([
            Argon2idAuthenticationMaterial::MEMORY_KEY,
            Argon2idAuthenticationMaterial::ITERATIONS_KEY,
            Argon2idAuthenticationMaterial::LANES_KEY,
        ], $names);

        // And the platform's register is the one that declares them, rather than
        // a list this test happens to agree with.
        self::assertInstanceOf(PolicyRegister::class, PolicyServiceProvider::declaredValues());
    }
}
