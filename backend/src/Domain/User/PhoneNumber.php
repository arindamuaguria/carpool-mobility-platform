<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use InvalidArgumentException;

/**
 * The one mandatory identifying detail (`BAD-RULE-043`).
 *
 * A value object rather than a string, because it is the account's identity
 * (`FRD-FR-002`), its authentication factor (`SEC-015`) and its natural key
 * (`DB-028`) — three reasons for a bare string to be passed to the wrong
 * parameter unnoticed.
 *
 * ## What is validated here, and what is not
 *
 * `FRD-FR-003` requires registration to be rejected *"where any mandatory detail
 * is absent or **malformed**"*. **No document states what malformed means for a
 * phone number.** No format, no country handling, no length rule and no
 * normalisation is specified anywhere in the chain, and `BAD-RULE-005` records
 * the verification mechanism rather than the format.
 *
 * So this validates only what is decidable without inventing a rule:
 *
 * - **not empty**, which `FRD-FR-003`'s *"absent"* half requires;
 * - **no internal whitespace**, because a value that differs only by spacing
 *   would defeat the unique constraint `DB-209` ‡ requires for `FRD-FR-004` —
 *   two spellings of one number are one number, and the constraint cannot see
 *   that;
 * - **within the column**, at 32 characters, which is a fact about the schema and
 *   not a business rule.
 *
 * It does **not** impose E.164, a country prefix, a digit count or a
 * canonicalisation. Each would be a business decision about who may register,
 * taken here where nobody would look for it. The format rule is reported as
 * unstated rather than chosen.
 */
final class PhoneNumber
{
    /**
     * `op_users.phone_number` is `VARCHAR(32)`. A value the column cannot hold is
     * refused here rather than by the database, so the refusal names the field.
     */
    private const MAXIMUM_LENGTH = 32;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'FRD-FR-003 / BAD-RULE-043: a phone number is the one mandatory identifying detail, and this one is absent.'
            );
        }

        if (preg_match('/\s/', $trimmed) === 1) {
            throw new InvalidArgumentException(
                'A phone number carries no internal whitespace. FRD-FR-004 refuses a number already registered, and '
                .'DB-209 ‡ enforces that by a unique constraint the database cannot see through two spellings of one number.'
            );
        }

        if (mb_strlen($trimmed) > self::MAXIMUM_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A phone number is at most %d characters, which is what op_users.phone_number holds.',
                self::MAXIMUM_LENGTH,
            ));
        }

        return new self($trimmed);
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
