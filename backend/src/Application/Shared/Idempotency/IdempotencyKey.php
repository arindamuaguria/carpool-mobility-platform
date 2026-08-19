<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Idempotency;

use InvalidArgumentException;

/**
 * A caller-generated key guarding one state-changing operation.
 *
 * `API-059`: caller-generated and **opaque to the platform** — nothing here
 * parses it, derives from it or assigns it meaning. `API-060`: it is scoped to
 * the acting actor and the operation, which is a property of the registry
 * (`CMP-IMP-025`), not of the key.
 *
 * `API-057` ‡ / `BE-045`: every state-changing operation carries one.
 * `API-058` ‡: its absence is an invalid request, refused at the interface
 * boundary before an application service is reached.
 *
 * The registry that gives the key its replay semantics — `API-061` ‡ same
 * transaction, `API-062` ‡ replay, `API-063` ‡ reuse with different content — is
 * `CMP-IMP-025` and does not exist yet. This type is the contract the command
 * interface needs today.
 */
final class IdempotencyKey
{
    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException(
                'API-057: a state-changing operation requires an idempotency key.'
            );
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
