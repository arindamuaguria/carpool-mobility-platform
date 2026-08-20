<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyNotSet;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\Policy\PolicyValue;

/**
 * Policy values held in memory, over a register the test declares.
 *
 * `TC-029` keeps a domain test away from a database, and a reader of policy
 * configuration otherwise needs one. The register is the test's own —
 * `PolicyRegister`'s own note says exactly that: *"a test constructs its own with
 * its own keys, so that exercising the store never requires adding a key to the
 * platform's."*
 *
 * `DB-153` ‡ and `SRS-REQ-158` are both honoured rather than shortcut: an
 * undeclared key raises through the register, and a declared key with no value
 * raises {@see PolicyNotSet}. A double that answered anyway would let a test pass
 * on behaviour the platform does not have.
 */
final class InMemoryPolicyStore implements PolicyStore
{
    /**
     * @param  array<string, string>  $values  raw values, by key name
     */
    public function __construct(
        private readonly PolicyRegister $register,
        private readonly array $values,
    ) {}

    public function read(PolicyKey $key): PolicyValue
    {
        // Through the register, so PolicyNotDeclared arrives from the same place
        // it would in production.
        $declared = $this->register->key($key->name());

        if (! array_key_exists($declared->name(), $this->values)) {
            throw PolicyNotSet::forKey($declared);
        }

        // BE-167: a value carries the version it came from. One version here —
        // nothing in memory is ever changed — and that it has one at all is what
        // keeps a caller recording it.
        return new PolicyValue($declared, $this->values[$declared->name()], 1);
    }

    public function isSet(PolicyKey $key): bool
    {
        return $this->register->declares($key->name())
            && array_key_exists($key->name(), $this->values);
    }
}
