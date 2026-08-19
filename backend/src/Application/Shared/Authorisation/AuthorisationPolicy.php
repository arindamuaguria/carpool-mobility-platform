<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

/**
 * The closed declaration of which operations have a rule.
 *
 * `SEC-055` ‡: *"Authorisation shall be deny-by-default; an operation with no
 * stated rule shall be refused."* `SADR-06` records the consequence and accepts
 * it: *"A new operation is refused until someone states its rule, which fails
 * safe"* — and, in the same breath, *"Every operation needs an explicit rule
 * before it works at all."*
 *
 * So there is no `ruleFor()` that returns a permissive default. An operation
 * absent from this policy has no rule, and {@see Authoriser} refuses it.
 *
 * A policy is a **value**, not a global: a test declares its own, and exercising
 * the authoriser never requires adding a rule to the platform.
 */
final class AuthorisationPolicy
{
    /** @var array<string, AuthorisationRule> */
    private readonly array $rules;

    /**
     * @param  array<string, AuthorisationRule>  $rules
     */
    private function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * @param  array<string, AuthorisationRule>  $rules  keyed by operation name
     */
    public static function of(array $rules = []): self
    {
        return new self($rules);
    }

    public function statesARuleFor(Operation $operation): bool
    {
        return array_key_exists($operation->name(), $this->rules);
    }

    /**
     * The stated rule, or null where none is stated — which is a refusal, not a
     * permission (`SEC-055` ‡).
     */
    public function ruleFor(Operation $operation): ?AuthorisationRule
    {
        return $this->rules[$operation->name()] ?? null;
    }

    /**
     * Every operation with a stated rule, and what it requires.
     *
     * `SADR-06` makes one path the whole defence of `TB-1` and `TB-2`; a
     * reviewer has to be able to read what that path permits.
     *
     * @return array<string, string>
     */
    public function catalogue(): array
    {
        $catalogue = [];

        foreach ($this->rules as $operation => $rule) {
            $catalogue[$operation] = $rule->describe();
        }

        ksort($catalogue);

        return $catalogue;
    }

    public function count(): int
    {
        return count($this->rules);
    }
}
