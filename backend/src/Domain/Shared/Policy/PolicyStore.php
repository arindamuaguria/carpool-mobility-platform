<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Policy;

/**
 * The one accessor for policy configuration (`BE-168`).
 *
 * *"Policy configuration shall be read through one accessor, not read
 * directly."* A second path — a query in a repository, a helper in a service —
 * would be a place `BE-167`'s version recording and `BE-169`'s caching did not
 * happen, and a place `DB-153` ‡'s absence rule could be sidestepped.
 *
 * `BE-171`: this is **not** deployment configuration and is never held in an
 * environment variable. `ARCH-145` makes deployment configuration
 * deploy-time and not runtime-editable; `ARCH-146` makes policy configuration
 * runtime-editable by an authorised operator with every change audited. They are
 * different things with different lifecycles, and PolicyRulesTest fails the
 * build if this store reaches for an environment variable.
 *
 * `BE-169`: values are read on nearly every decision, so an implementation
 * caches. `BE-170`: the cache is invalidated on change and no restart is
 * required.
 */
interface PolicyStore
{
    /**
     * @throws PolicyNotDeclared where the register does not declare the key
     *                           (`BE-172` ‡, `DB-153` ‡)
     * @throws PolicyNotSet where the key is declared but no value has been set
     */
    public function read(PolicyKey $key): PolicyValue;

    /**
     * Whether a value has been set for a declared key.
     *
     * `SRS-REQ-158` requires an attempt to use an unconfigured value to be
     * rejected. A caller that can act without the value asks first; a caller that
     * cannot lets {@see read()} raise.
     */
    public function isSet(PolicyKey $key): bool;
}
