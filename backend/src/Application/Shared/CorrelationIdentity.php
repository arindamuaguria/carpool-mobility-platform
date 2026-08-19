<?php

declare(strict_types=1);

namespace Cmp\Application\Shared;

use InvalidArgumentException;

/**
 * The identity that ties a caller's report of a fault to the platform's record
 * of it (`API-093`, `BE-199`).
 *
 * It carries no meaning: it is not a sequence, not a timestamp and not derived
 * from any internal identifier, so that quoting it discloses nothing
 * (`API-092` ‡, `BE-189` ‡).
 */
final class CorrelationIdentity
{
    private function __construct(private readonly string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('A correlation identity must not be empty.');
        }
    }

    public static function fromString(string $value): self
    {
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
