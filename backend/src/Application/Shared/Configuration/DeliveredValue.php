<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Configuration;

use Cmp\Domain\Shared\Policy\PolicyKey;

/**
 * One value CMP-DOC-10 §14.2 delivers to a client, and how it is obtained.
 *
 * `API-187` ‡: *"the client shall obtain every policy value it needs from this
 * resource and shall embed none."* `API-196` closes the other side — a
 * configuration change *"shall not require a client release, and shall not be
 * delivered by any other means"*. So §14.2's list is a contract, and this is one
 * row of it.
 *
 * ## Two ways a value is obtained, and why both are here
 *
 * A value is either **operator configuration**, read through the policy store
 * (`BADR-12`, `BE-168`), or **derived from the platform's own contract**, which
 * has no operator and no version of its own — `AADR-14`'s refusal reason
 * identifier set is the example, and it changes only when the platform's code
 * changes.
 *
 * Keeping them in one register rather than two is what lets `API-188`'s single
 * response version cover everything served: a client comparing one number learns
 * that *something* changed, whichever kind it was.
 *
 * ## `API-195` — public, or not
 *
 * *"The public subset reachable without a session shall contain no value that
 * discloses platform state."* Each entry says which it is, so the subset is a
 * property of the register rather than of the controller that reads it — and
 * `ConfigurationTest` asserts the unauthenticated response holds only public
 * entries.
 */
final class DeliveredValue
{
    private function __construct(
        private readonly string $name,
        private readonly ?PolicyKey $key,
        private readonly bool $public,
    ) {}

    /**
     * A value an operator sets, read through the policy store.
     *
     * The `$name` is the name the **client** knows it by, which need not be the
     * policy key: `API-187` ‡ makes this resource the client's contract, and
     * `DB-153` ‡ makes the key the platform's. Keeping them separate means a key
     * can be renamed without breaking a client, which `API-084`'s reasoning about
     * a reason identifier applies equally to.
     */
    public static function fromPolicy(string $name, PolicyKey $key, bool $public): self
    {
        return new self($name, $key, $public);
    }

    /**
     * A value derived from the platform's own contract rather than configured.
     *
     * `AADR-14`'s consequence in terms: *"reasons are enumerable and testable"*
     * and *"the identifier set becomes part of the contract and is versioned with
     * it"* — versioned with the **interface**, that is, not by `BE-167`. So there
     * is no policy key and no operator; there is the code, and `API-084` forbids
     * an identifier being removed or repurposed within a version.
     */
    public static function derived(string $name, bool $public): self
    {
        return new self($name, null, $public);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The policy key behind it, or null where the value is derived.
     */
    public function key(): ?PolicyKey
    {
        return $this->key;
    }

    /**
     * `API-195`: whether it may be served without a session.
     */
    public function isPublic(): bool
    {
        return $this->public;
    }
}
