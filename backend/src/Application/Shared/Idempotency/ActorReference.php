<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Idempotency;

use InvalidArgumentException;

/**
 * The actor an idempotency key is scoped to (`API-060`).
 *
 * An opaque reference, never the internal primary key of a row (`DB-024` ‡). The
 * session mechanism that produces one is CMP-DOC-13's and arrives with FEAT-004;
 * this type is the contract the registry needs today.
 */
final class ActorReference
{
    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('API-060: an idempotency key is scoped to an acting actor.');
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
