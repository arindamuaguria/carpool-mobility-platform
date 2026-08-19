<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Policy;

use Cmp\Application\Shared\Policy\PolicyCache;
use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyNotDeclared;
use Cmp\Domain\Shared\Policy\PolicyNotSet;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\Policy\PolicyValue;
use Illuminate\Database\ConnectionInterface;

/**
 * The policy store over `cfg_policy_values` and `cfg_policy_versions`.
 *
 * The value in force is the **highest version**. There is no current-version
 * pointer to keep in step, so reading is a join and applying a change is a
 * single insert — which is what lets the application account hold `SELECT` and
 * `INSERT` on `cfg_` and nothing more, making `DB-152` a property of the
 * credential rather than of the code.
 *
 * `BE-169`: policy is read on nearly every decision, so a value read once is
 * memoised for the rest of the process. `BE-170`: {@see forget()} clears it on
 * change, so no restart is required.
 *
 * The cache is **per process and nothing more**. `BADR-12` calls for an
 * in-process cache with a bounded lifetime; the bound here is the request or the
 * worker run, which needs no lifetime value to be invented — and CMP-DOC-09
 * §13.2 lists none. A change is therefore visible to every instance by its next
 * request. `BADR-12`'s consequence — *"cache invalidation across instances must
 * be handled"* — is bounded to that window rather than solved, and solving it
 * needs a shared cache the platform does not yet have: the cache store is `file`
 * and no `mch_` table backs one.
 *
 * `DB-153` ‡ / `BE-172` ‡: an undeclared key raises. There is no default and no
 * fallback, because absence is the mechanism by which a policy value cannot
 * relax an absolute rule.
 */
final class DatabasePolicyStore implements PolicyCache, PolicyStore
{
    public const VALUES_TABLE = 'cfg_policy_values';

    public const VERSIONS_TABLE = 'cfg_policy_versions';

    /** @var array<string, PolicyValue|null> */
    private array $memoised = [];

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly PolicyRegister $register,
    ) {}

    public function read(PolicyKey $key): PolicyValue
    {
        return $this->resolve($key) ?? throw PolicyNotSet::forKey($key);
    }

    public function isSet(PolicyKey $key): bool
    {
        return $this->resolve($key) !== null;
    }

    /**
     * `BE-170`: invalidated on change, no restart required.
     */
    public function forget(PolicyKey $key): void
    {
        unset($this->memoised[$key->name()]);
    }

    public function forgetAll(): void
    {
        $this->memoised = [];
    }

    /**
     * @throws PolicyNotDeclared
     */
    private function resolve(PolicyKey $key): ?PolicyValue
    {
        if (! $this->register->declares($key->name())) {
            throw PolicyNotDeclared::forKey($key->name());
        }

        if (array_key_exists($key->name(), $this->memoised)) {
            return $this->memoised[$key->name()];
        }

        /** @var list<object{value_text: string, version: int|string}> $rows */
        $rows = $this->connection->select(
            'SELECT v.value_text, v.version'
            .' FROM '.self::VALUES_TABLE.' AS pv'
            .' INNER JOIN '.self::VERSIONS_TABLE.' AS v ON v.policy_value_id = pv.id'
            .' WHERE pv.policy_key = ?'
            .' ORDER BY v.version DESC'
            .' LIMIT 1',
            [$key->name()],
        );

        $value = $rows === []
            ? null
            // BE-167: the version is carried with the value, so a decision can
            // record which one it used.
            : new PolicyValue($key, $rows[0]->value_text, (int) $rows[0]->version);

        return $this->memoised[$key->name()] = $value;
    }
}
