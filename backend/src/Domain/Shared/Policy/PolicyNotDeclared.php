<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Policy;

use RuntimeException;

/**
 * A policy value was asked for that the register does not declare.
 *
 * `DB-153` ‡ is the reason this is an error rather than a default: *"a policy
 * value shall never be capable of relaxing an absolute rule; values that could
 * are **absent from the table** rather than validated."* Absence is the
 * mechanism. `BE-172` ‡ says the same from the other side — no policy value
 * overrides an absolute business rule — and `BADR-12` closes it: *"the policy
 * service exposes no accessor for behaviour fixed by an absolute rule."*
 *
 * Returning a default for an undeclared key would reintroduce exactly the
 * accessor `BADR-12` says must not exist.
 */
final class PolicyNotDeclared extends RuntimeException
{
    public static function forKey(string $name): self
    {
        return new self(sprintf(
            'BE-172 ‡ / DB-153 ‡: "%s" is not a declared policy value. A value that could relax an absolute '
            .'rule is absent from the register rather than validated, and there is no default to fall back on.',
            $name,
        ));
    }
}
