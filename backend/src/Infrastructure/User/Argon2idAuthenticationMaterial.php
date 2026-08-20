<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\User;

use Cmp\Application\User\HashCostBelowFloor;
use Cmp\Application\User\HashesAuthenticationMaterial;
use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyStore;
use InvalidArgumentException;

/**
 * `SEC-244` ‡ — Argon2id, at a cost the operator sets and beneath a floor the
 * platform refuses to go below.
 *
 * `SEC-028` ‡ requires a memory-hard, salted, tunable password hash and names no
 * construction. `SEC-244` ‡ names Argon2id, for the reason recorded in
 * CMP-DOC-13 §14.3: its encoded output carries **its own salt** (`SEC-029` ‡) and
 * **its own parameters** (`SEC-032` ‡), so neither needs a column of its own and
 * neither can drift out of step with the value it describes.
 *
 * ## Three numbers, and none of them is in this file
 *
 * `SEC-030` makes the costs **configuration, tunable without a code change**.
 * `SEC-031` says the operating values are deployment-time, set against the
 * deployed hardware — and `BAD-DEP-009` has selected no hosting, so there is no
 * hardware to set them against and none is assumed here.
 *
 * They are therefore three policy keys, **declared and unset**. Until an operator
 * applies each, {@see PolicyStore::read()} raises `PolicyNotSet` and nothing
 * hashes. That is `SRS-REQ-158` working — an unconfigured value is rejected —
 * and it is the same posture `SEC-039` ‡'s session lifetime takes.
 *
 * ## The floor is the one figure in code, and `SEC-244` ‡ put it there
 *
 * *"the platform shall refuse to hash where a configured cost is below the floor
 * of **19456 KiB memory, 2 iterations and 1 lane** — a published minimum, not a
 * library default."*
 *
 * The distinction matters. A library default is whatever the library shipped and
 * changes when the library does; this floor is a stated minimum that a reviewer
 * can check against the publication it came from. A cost **above** the floor is a
 * deployment decision under `SEC-031`; a cost below it is refused rather than
 * clamped, so that a misconfiguration is visible rather than absorbed —
 * {@see HashCostBelowFloor}.
 *
 * ## What is deliberately not here
 *
 * No fallback to another algorithm, and no `password_hash()` with `PASSWORD_DEFAULT`.
 * `PASSWORD_DEFAULT` is defined to change between PHP releases, which would move
 * the construction `SEC-244` ‡ fixed without anybody deciding to.
 */
final class Argon2idAuthenticationMaterial implements HashesAuthenticationMaterial
{
    /**
     * `SEC-244` ‡'s floor: memory in KiB, iterations, lanes.
     *
     * Public so that a test asserts the platform's floor rather than a copy of
     * it, and so that a change here fails a test naming `SEC-244` ‡.
     */
    public const FLOOR_MEMORY_KIB = 19456;

    public const FLOOR_ITERATIONS = 2;

    public const FLOOR_LANES = 1;

    /**
     * The three keys `PolicyServiceProvider` declares for `SEC-030`'s costs.
     *
     * Named here rather than there so that the reader and the declaration cannot
     * name different keys — `DB-153` ‡ makes absence from the register the
     * mechanism, and a typo in one of two string literals would look exactly like
     * an undeclared key.
     */
    public const MEMORY_KEY = 'authentication.hash.memory_kib';

    public const ITERATIONS_KEY = 'authentication.hash.iterations';

    public const LANES_KEY = 'authentication.hash.lanes';

    public function __construct(
        private readonly PolicyStore $policy,
        private readonly PolicyKey $memoryKib,
        private readonly PolicyKey $iterations,
        private readonly PolicyKey $lanes,
    ) {}

    public function hash(string $material): string
    {
        if ($material === '') {
            throw new InvalidArgumentException(
                'SEC-028 ‡: an empty demonstration is not authentication material, and hashing one '
                .'would produce a stored value that any empty presentation would match.'
            );
        }

        // No failure branch: since PHP 8 this raises rather than returning a
        // value the caller might store. FRD-FR-248 ‡ wants exactly that — a
        // credential that silently failed to hash is worse than an error,
        // because the row would look written.
        return password_hash($material, PASSWORD_ARGON2ID, $this->cost());
    }

    public function matches(string $material, string $stored): bool
    {
        if ($material === '' || $stored === '') {
            return false;
        }

        // SEC-020 ‡: password_verify() compares in constant time with respect to
        // the content, so the duration of a failure says nothing about how much
        // of the demonstration was right. FRD-FR-015 and SEC-021 need that as
        // much as the response body does — a timing difference discloses through
        // a channel no response shape can close.
        return password_verify($material, $stored);
    }

    public function needsRehash(string $stored): bool
    {
        if ($stored === '') {
            return false;
        }

        // SEC-032 ‡: below the current setting, so it is re-hashed on next
        // successful authentication. The parameters come out of the stored value
        // itself, which is why SEC-244 ‡ chose a construction that carries them.
        return password_needs_rehash($stored, PASSWORD_ARGON2ID, $this->cost());
    }

    /**
     * The operator's cost, checked against `SEC-244` ‡'s floor.
     *
     * Read on every call rather than held: `BE-169` caches in the store and
     * `BE-170` invalidates on change, so a re-tuning under `SEC-030` takes effect
     * without a restart — which is the whole point of the costs being policy
     * configuration rather than deployment configuration (`BE-171`).
     *
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function cost(): array
    {
        $memory = $this->policy->read($this->memoryKib)->asInteger();
        $time = $this->policy->read($this->iterations)->asInteger();
        $lanes = $this->policy->read($this->lanes)->asInteger();

        $beneath = [];

        if ($memory < self::FLOOR_MEMORY_KIB) {
            $beneath[] = sprintf('memory %d KiB is below %d KiB', $memory, self::FLOOR_MEMORY_KIB);
        }

        if ($time < self::FLOOR_ITERATIONS) {
            $beneath[] = sprintf('%d iterations is below %d', $time, self::FLOOR_ITERATIONS);
        }

        if ($lanes < self::FLOOR_LANES) {
            $beneath[] = sprintf('%d lanes is below %d', $lanes, self::FLOOR_LANES);
        }

        if ($beneath !== []) {
            // Named in the message because an operator reads it and has to know
            // which of the three to raise. API-092 ‡ keeps it out of the
            // response — the caller sees a correlation identity and nothing else.
            throw new HashCostBelowFloor(sprintf(
                'SEC-244 ‡: the platform refuses to hash beneath its floor — %s. Raise the policy value '
                .'rather than lowering the floor; SEC-031 makes the cost a deployment decision above the '
                .'floor and not below it.',
                implode('; ', $beneath),
            ));
        }

        return ['memory_cost' => $memory, 'time_cost' => $time, 'threads' => $lanes];
    }
}
