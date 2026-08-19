<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Policy;

use RuntimeException;

/**
 * A policy value does not satisfy the type declared for its key.
 *
 * `BE-174` / `ARCH-148`: a policy change is validated against its declared
 * constraints **before it takes effect**, and an invalid configuration is
 * rejected rather than applied.
 */
final class PolicyValueInvalid extends RuntimeException
{
    public static function forKey(PolicyKey $key, string $rawValue): self
    {
        return new self(sprintf(
            'BE-174: "%s" is declared as %s and does not accept %s.',
            $key->name(),
            $key->type()->value,
            $rawValue === '' ? 'an empty value' : '"'.$rawValue.'"',
        ));
    }
}
