<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

use InvalidArgumentException;

/**
 * Something a role grants the holder the ability to do.
 *
 * `SD-04`: *"An operator gains capability, never exemption."* `SEC-010` ‡ and
 * `SEC-062` ‡ say the same twice over: a role grants **additional capability**
 * and never exemption from an absolute business rule, and no role exists that is
 * exempt from one.
 *
 * That is why a capability is a positive grant and there is no such thing as an
 * exemption here to be named. The authorisation evaluation returns permit or
 * refuse; it cannot return *"and skip the domain rule"*, because the domain runs
 * afterwards either way (`BE-044`).
 */
final class Capability
{
    private function __construct(private readonly string $name) {}

    public static function named(string $name): self
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a capability name.', $name));
        }

        return new self($name);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }
}
