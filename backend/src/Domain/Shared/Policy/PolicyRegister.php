<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Policy;

/**
 * The closed declaration of which policy values exist.
 *
 * `DB-153` ‡: *"a policy value shall never be **capable** of relaxing an absolute
 * rule; values that could are **absent from the table** rather than validated."*
 * That sentence is the whole design. The protection is not a check that runs
 * when someone sets a dangerous value — it is that there is nowhere to put one.
 *
 * `BE-172` ‡ states the same obligation, and `BADR-12` closes it: *"the policy
 * service exposes no accessor for behaviour fixed by an absolute rule."*
 *
 * A register is a **value**, not a global. The platform declares its own in one
 * place, at the composition root; a test constructs its own with its own keys, so
 * that exercising the store never requires adding a key to the platform’s.
 *
 * Nothing here names where that composition root is. `BE-003` has the Domain
 * depend on nothing, and a class naming an Infrastructure class in prose has a
 * dependency a reader will follow even where the compiler does not.
 */
final class PolicyRegister
{
    /** @var array<string, PolicyKey> */
    private readonly array $keys;

    private function __construct(PolicyKey ...$keys)
    {
        $indexed = [];

        foreach ($keys as $key) {
            $indexed[$key->name()] = $key;
        }

        $this->keys = $indexed;
    }

    public static function of(PolicyKey ...$keys): self
    {
        return new self(...$keys);
    }

    public function declares(string $name): bool
    {
        return array_key_exists($name, $this->keys);
    }

    /**
     * @throws PolicyNotDeclared
     */
    public function key(string $name): PolicyKey
    {
        return $this->keys[$name] ?? throw PolicyNotDeclared::forKey($name);
    }

    /**
     * The register as a catalogue: every declared value, and what reads it.
     *
     * `ARCH-146` requires every policy change to be audited; a reviewer needs to
     * see what may be changed at all before that means anything.
     *
     * @return array<string, PolicyKey>
     */
    public function catalogue(): array
    {
        $catalogue = $this->keys;
        ksort($catalogue);

        return $catalogue;
    }

    public function count(): int
    {
        return count($this->keys);
    }
}
