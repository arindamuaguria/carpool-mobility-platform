<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Policy;

use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Shared\Policy\PolicyNotDeclared;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyValue;
use Cmp\Domain\Shared\Policy\PolicyValueInvalid;
use InvalidArgumentException;

/**
 * Applies a new value to a declared policy key.
 *
 * `BADR-12`: *"Every change is written by an operator action, validated before
 * application, and recorded evidentially."* This is that operation, and it is
 * the only one — there is no path that writes a policy value without validating
 * it and recording who changed it from what.
 *
 * The order matters and is not incidental:
 *
 * 1. **The key must be declared** (`DB-153` ‡, `BE-172` ‡). An undeclared key
 *    raises before anything is read or written. Absence is the mechanism by
 *    which a policy value cannot relax an absolute rule; validating an
 *    undeclared key would mean the register was advisory.
 * 2. **The value must satisfy the declared type** (`BE-174`, `ARCH-148`) —
 *    *"validated on change, and an invalid configuration rejected rather than
 *    applied"*. Rejection happens before the transaction opens, so an invalid
 *    change never reaches the store at all.
 * 3. **A new version is appended** (`DB-152`), never an update in place, with
 *    the actor and the previous value (`BE-173`).
 * 4. **The cache is cleared** (`BE-170`), so no restart is required.
 *
 * `BE-044` requires authorisation to be evaluated before the domain is invoked,
 * on the single application-layer path of `BADR-14`. That path is `CMP-IMP-033`
 * and does not exist yet; the actor is recorded here but **not yet checked**,
 * and this operation must not be exposed through any interface surface until it
 * is.
 */
final class ChangePolicyValue
{
    public function __construct(
        private readonly TransactionBoundary $transaction,
        private readonly PolicyRegister $register,
        private readonly RecordsPolicyChanges $recorder,
        private readonly PolicyCache $cache,
    ) {}

    /**
     * @param  string  $actor  who is making the change (`BE-173`)
     *
     * @throws PolicyNotDeclared where the register does not declare the key
     * @throws PolicyValueInvalid where the value does not satisfy the declared type
     */
    public function apply(string $keyName, string $newRawValue, string $actor): PolicyValue
    {
        // 1. DB-153 ‡ / BE-172 ‡ — raises where the key is not declared.
        $key = $this->register->key($keyName);

        // 2. BE-174 / ARCH-148 — rejected rather than applied, before the
        //    transaction opens.
        if (! $key->accepts($newRawValue)) {
            throw PolicyValueInvalid::forKey($key, $newRawValue);
        }

        if (trim($actor) === '') {
            throw new InvalidArgumentException(
                'BE-173: a policy change is recorded with the actor who made it. ARCH-146 requires every '
                .'change audited, and a change nobody is recorded as having made is not audited.'
            );
        }

        $version = $this->transaction->transactional(function () use ($key, $newRawValue, $actor): int {
            $this->recorder->declare($key);

            $previous = $this->recorder->currentRawValue($key);

            // 3. DB-152 — a new version, never an update in place.
            return $this->recorder->appendVersion($key, $newRawValue, $previous, $actor);
        });

        // 4. BE-170 — invalidated on change; no restart required.
        $this->cache->forget($key);

        return new PolicyValue($key, $newRawValue, $version);
    }
}
