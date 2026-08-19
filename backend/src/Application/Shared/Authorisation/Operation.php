<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

use InvalidArgumentException;

/**
 * A named thing the platform can be asked to do.
 *
 * `SEC-059`: an authorisation rule is expressed **once** and evaluated
 * identically for every caller type. The operation is the key that rule hangs
 * on, and it is deliberately not qualified by caller — there is no
 * `ride.publish.client` and `ride.publish.admin`, because `SADR-06` rejected two
 * paths in its own words: *"two paths diverge, and the one used less is the one
 * that rots."*
 */
final class Operation
{
    private function __construct(private readonly string $name) {}

    public static function named(string $name): self
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not an operation name. A name is lower-case and dot-separated, naming its area '
                .'and what it does, so that the authorisation policy reads as a list of operations.',
                $name,
            ));
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
