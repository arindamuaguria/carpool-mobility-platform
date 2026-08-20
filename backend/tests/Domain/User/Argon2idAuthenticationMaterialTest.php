<?php

declare(strict_types=1);

namespace Tests\Domain\User;

use Cmp\Application\User\HashCostBelowFloor;
use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyType;
use Cmp\Infrastructure\User\Argon2idAuthenticationMaterial;
use Tests\Domain\DomainTestCase;
use Tests\Domain\Shared\Doubles\InMemoryPolicyStore;

/**
 * `SEC-244` ‡ — Argon2id, and the floor the platform refuses to hash beneath.
 *
 * **Level 2** (`TC-029`): no database, no framework, no network. The costs come
 * from a register the test declares and a store it holds in memory, so what is
 * exercised is the construction and the floor rather than the policy plumbing —
 * that has its own tests at level 3.
 *
 * `FR-04` requires a negative test for every ‡ requirement. `SEC-244` ‡ has three
 * of them, one per cost, because the floor is stated per cost and a check that
 * passed on two of the three would look like a working floor.
 */
final class Argon2idAuthenticationMaterialTest extends DomainTestCase
{
    /** A demonstration of the shape `SEC-015` describes — short, and read off a screen. */
    private const MATERIAL = '481920';

    public function test_the_floor_is_the_published_minimum_and_not_a_library_default(): void
    {
        // SEC-244 ‡ states three figures, and states that they are "a published
        // minimum, not a library default". Asserted against the statement rather
        // than against whatever PHP ships, so that a PHP upgrade moving
        // PASSWORD_ARGON2ID's defaults cannot move the platform's floor.
        self::assertSame(19456, Argon2idAuthenticationMaterial::FLOOR_MEMORY_KIB);
        self::assertSame(2, Argon2idAuthenticationMaterial::FLOOR_ITERATIONS);
        self::assertSame(1, Argon2idAuthenticationMaterial::FLOOR_LANES);
    }

    public function test_a_stored_value_is_argon2id_and_carries_its_own_parameters(): void
    {
        // SEC-244 ‡: Argon2id, "whose encoded output carries its own salt
        // (SEC-029 ‡) and its own parameters (SEC-032 ‡)". Both are why neither
        // needs a column, and DB-042 ‡'s single `material` column is the
        // consequence.
        $stored = $this->hasher()->hash(self::MATERIAL);

        self::assertStringStartsWith('$argon2id$', $stored);
        self::assertStringContainsString('m=19456', $stored);
        self::assertStringContainsString('t=2', $stored);
        self::assertStringContainsString('p=1', $stored);
    }

    public function test_two_hashes_of_the_same_demonstration_differ(): void
    {
        // SEC-029 ‡: each stored value carries a unique salt. That the two differ
        // is what proves the salt is per-value — and it is also why a stored
        // value can never be used as a lookup key, unlike SEC-036 ‡'s session
        // token hash.
        $hasher = $this->hasher();

        self::assertNotSame($hasher->hash(self::MATERIAL), $hasher->hash(self::MATERIAL));
    }

    public function test_a_correct_demonstration_matches_and_an_incorrect_one_does_not(): void
    {
        $hasher = $this->hasher();
        $stored = $hasher->hash(self::MATERIAL);

        self::assertTrue($hasher->matches(self::MATERIAL, $stored));
        self::assertFalse($hasher->matches('481921', $stored));
        self::assertFalse($hasher->matches('', $stored));
    }

    public function test_nothing_stored_can_be_presented_back_as_a_credential(): void
    {
        // SEC-033 ‡ / NFR-053: no store the platform controls holds a value that
        // can be presented back to it as a credential. Presenting the stored
        // value itself is the obvious attempt, and it fails.
        $hasher = $this->hasher();
        $stored = $hasher->hash(self::MATERIAL);

        self::assertFalse($hasher->matches($stored, $stored));
    }

    public function test_a_malformed_stored_value_refuses_rather_than_raising(): void
    {
        // SEC-021 / FRD-FR-015: every refusal on this path looks alike. A row
        // that cannot be read is a refusal like any other, because raising here
        // would make one kind of failure distinguishable from the rest by the
        // shape of what came back.
        $hasher = $this->hasher();

        self::assertFalse($hasher->matches(self::MATERIAL, 'not-a-hash'));
        self::assertFalse($hasher->matches(self::MATERIAL, ''));
    }

    public function test_hashing_is_refused_where_the_memory_cost_is_beneath_the_floor(): void
    {
        // Negative test 1 of 3 for SEC-244 ‡ (FR-04). One KiB below the floor,
        // so this fails if the comparison is ever written as <= or against a
        // different figure.
        $this->expectException(HashCostBelowFloor::class);
        $this->expectExceptionMessageMatches('/memory 19455 KiB is below 19456 KiB/');

        $this->hasher(memory: Argon2idAuthenticationMaterial::FLOOR_MEMORY_KIB - 1)->hash(self::MATERIAL);
    }

    public function test_hashing_is_refused_where_the_iteration_count_is_beneath_the_floor(): void
    {
        // Negative test 2 of 3.
        $this->expectException(HashCostBelowFloor::class);
        $this->expectExceptionMessageMatches('/1 iterations is below 2/');

        $this->hasher(iterations: 1)->hash(self::MATERIAL);
    }

    public function test_hashing_is_refused_where_the_lane_count_is_beneath_the_floor(): void
    {
        // Negative test 3 of 3.
        $this->expectException(HashCostBelowFloor::class);
        $this->expectExceptionMessageMatches('/0 lanes is below 1/');

        $this->hasher(lanes: 0)->hash(self::MATERIAL);
    }

    public function test_the_refusal_names_every_cost_beneath_the_floor_and_not_only_the_first(): void
    {
        // An operator has to raise all three, and being told about one at a time
        // means three deployments to discover three problems. API-079 takes the
        // same position for a caller's invalid request, and the reasoning is the
        // same: report what is detectable, not what is first.
        $this->expectException(HashCostBelowFloor::class);
        $this->expectExceptionMessageMatches('/memory .*; .*iterations .*; .*lanes /');

        $this->hasher(memory: 8, iterations: 1, lanes: 0)->hash(self::MATERIAL);
    }

    public function test_the_floor_refuses_rather_than_quietly_raising_the_cost_to_it(): void
    {
        // SEC-030 requires the parameters to be re-tuned when hardware changes,
        // which somebody does by reading a number. A number the platform
        // silently overrode is a number nobody re-tunes — so nothing is stored
        // at all when the cost is too low.
        $hasher = $this->hasher(memory: 1024);
        $raised = false;

        try {
            $hasher->hash(self::MATERIAL);
        } catch (HashCostBelowFloor) {
            $raised = true;
        }

        self::assertTrue($raised, 'SEC-244 ‡: refuse to hash, not hash at the floor instead.');
    }

    public function test_a_value_stored_beneath_the_current_setting_is_marked_for_rehash(): void
    {
        // SEC-032 ‡: re-hashed on next successful authentication when its
        // parameters are below the current setting. The parameters come out of
        // the stored value itself, which is why SEC-244 ‡ chose a construction
        // that carries them.
        $stored = $this->hasher()->hash(self::MATERIAL);

        self::assertFalse($this->hasher()->needsRehash($stored));
        self::assertTrue($this->hasher(iterations: 3)->needsRehash($stored));
    }

    public function test_raising_the_cost_above_the_floor_is_permitted(): void
    {
        // SEC-031: a cost above the floor is a deployment decision. The floor
        // bounds one direction only — BE-172 ‡ is satisfied because the value
        // cannot relax SEC-028 ‡, not because it cannot move.
        $hasher = $this->hasher(memory: 65536, iterations: 4, lanes: 2);
        $stored = $hasher->hash(self::MATERIAL);

        self::assertStringContainsString('m=65536', $stored);
        self::assertTrue($hasher->matches(self::MATERIAL, $stored));
    }

    /**
     * A hasher whose three costs are set, at the floor unless overridden.
     *
     * The floor is used as the test's working cost because it is the cheapest
     * configuration the platform will run at, and a test suite that hashes at
     * production cost is a test suite nobody runs.
     */
    private function hasher(
        int $memory = Argon2idAuthenticationMaterial::FLOOR_MEMORY_KIB,
        int $iterations = Argon2idAuthenticationMaterial::FLOOR_ITERATIONS,
        int $lanes = Argon2idAuthenticationMaterial::FLOOR_LANES,
    ): Argon2idAuthenticationMaterial {
        $keys = [
            Argon2idAuthenticationMaterial::MEMORY_KEY => $memory,
            Argon2idAuthenticationMaterial::ITERATIONS_KEY => $iterations,
            Argon2idAuthenticationMaterial::LANES_KEY => $lanes,
        ];

        $declared = [];
        $values = [];

        foreach ($keys as $name => $value) {
            $declared[] = PolicyKey::of($name, PolicyType::Integer, 'SEC-030, under test.');
            $values[$name] = (string) $value;
        }

        $store = new InMemoryPolicyStore(PolicyRegister::of(...$declared), $values);

        return new Argon2idAuthenticationMaterial(
            $store,
            $declared[0],
            $declared[1],
            $declared[2],
        );
    }
}
