<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use Cmp\Domain\Shared\Time\Clock;
use InvalidArgumentException;

/**
 * The account's identity as anything outside the database sees it.
 *
 * `FRD-FR-005` ‡: *"a unique and persistent identifier that is never reused."*
 * `DB-022` ‡ makes it randomly generated and indexed; `DB-023` ‡ requires enough
 * entropy that enumeration is infeasible and **no meaning, no sequence and no
 * timestamp**; `DB-024` ‡ keeps the internal primary key out of anything a caller
 * can read, which is why this exists at all.
 *
 * ## Generated outside the Domain
 *
 * There is no `generate()` here. `DB-023` ‡ requires a cryptographically adequate
 * random source, and `BE-002` keeps the Domain free of the framework and of the
 * environment — a Domain type that reached for randomness would be a Domain type
 * that could not be tested without it, which `BE-040` and `TC-029` ‡ both
 * forbid. The reference is supplied to the aggregate, and generating one is the
 * infrastructure's, as {@see Clock} is for the instant.
 *
 * `SEC-087` ‡ is worth stating too: a protected value shall not be used as an
 * external identifier. This is random and derived from nothing, so it cannot be.
 */
final class UserReference
{
    /**
     * `op_users.external_id` is `CHAR(32)` — 16 random bytes in hexadecimal.
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
