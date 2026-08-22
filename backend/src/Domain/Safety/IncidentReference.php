<?php

declare(strict_types=1);

namespace Cmp\Domain\Safety;

use InvalidArgumentException;

/**
 * A safety incident's identity as anything outside the database sees it.
 *
 * `DB-022` ‡: every entity exposed through the interface carries a unique,
 * indexed, randomly generated external identifier. An incident is read at
 * `/safety/v1/incidents/{id}` (CMP-DOC-10 §12.1), so the rule reaches it.
 *
 * `DB-023` ‡ matters more here than almost anywhere. An identifier a caller
 * could enumerate would be a way to walk the list of people who have raised a
 * safety signal — the single most sensitive record the platform holds. Random,
 * meaningless, and carrying no sequence and no timestamp.
 *
 * Generated outside the Domain, for `UserReference`'s reason: `BE-002` and
 * `TC-029` ‡ keep the Domain free of the environment, so this validates a value
 * and never produces one.
 */
final class IncidentReference
{
    /**
     * `op_safety_incidents.external_id` is `CHAR(32)` — 16 random bytes in
     * hexadecimal, the same form and entropy as every other external identifier.
     */
    private const LENGTH = 32;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        if (strlen($value) !== self::LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'DB-022 ‡: an external identifier is %d characters; this one is %d.',
                self::LENGTH,
                strlen($value),
            ));
        }

        if (preg_match('/^[0-9a-f]{'.self::LENGTH.'}$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'DB-023 ‡: an external identifier is randomly generated and encodes no meaning, no sequence and '
                .'no timestamp. Lower-case hexadecimal is the form that carries none of the three.'
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
