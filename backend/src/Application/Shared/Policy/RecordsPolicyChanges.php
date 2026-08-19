<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Policy;

use Cmp\Domain\Shared\Policy\PolicyKey;

/**
 * Writes policy versions.
 *
 * `DB-152` / `BE-167`: **each change produces a new version rather than an update
 * in place.** There is no method here that overwrites a value, because there is
 * no circumstance in which one should.
 */
interface RecordsPolicyChanges
{
    /**
     * Ensures the declared key has a row, so a version can hang off it.
     *
     * Idempotent: declaring a key twice is not a change and produces no version.
     */
    public function declare(PolicyKey $key): void;

    /**
     * The raw value currently in force, or null where none has been set.
     */
    public function currentRawValue(PolicyKey $key): ?string;

    /**
     * Appends a version and makes it current.
     *
     * `BE-173`: recorded with the actor, the previous value and the new one.
     *
     * @return int the version number applied
     */
    public function appendVersion(
        PolicyKey $key,
        string $newRawValue,
        ?string $previousRawValue,
        string $actor,
    ): int;
}
