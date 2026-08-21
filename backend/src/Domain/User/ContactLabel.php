<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use InvalidArgumentException;

/**
 * What the user calls a nominated contact, where they call it anything.
 *
 * `UC-048` A1 records a **set** the user maintains, and `FRD-FR-183` validates
 * contact *details*. A set of bare numbers is one the user cannot tell apart,
 * which is what this exists for — and nothing else. It is **not** a name in the
 * sense `BAD-RULE-043` refuses to hold one for the user themselves: no document
 * asks the platform to know who the contact is, and nothing reads this but the
 * user who wrote it.
 *
 * ## Optional, deliberately
 *
 * No requirement makes a label mandatory. A contact with a number and no label
 * is usable for every purpose the platform has (`UC-048`'s success guarantee is
 * that the platform *holds* the contact), so refusing one would be a mandatory
 * field invented here.
 *
 * ## What is validated, and what is not
 *
 * The same discipline as {@see PhoneNumber}: only what is decidable without
 * inventing a rule.
 *
 * - **not blank once trimmed** — a label of spaces is the caller meaning to send
 *   nothing, and `null` already says that unambiguously;
 * - **no control characters**, because this is written back to the caller and a
 *   value carrying a line break would let one stored field look like two;
 * - **within the column**, at 64 characters, which is a fact about the schema.
 *
 * There is no character-set rule, no name format and no profanity judgement.
 * Each would be a decision about how a user may describe a person in their own
 * life, taken here where nobody would look for it.
 */
final class ContactLabel
{
    /**
     * `op_user_emergency_contacts.label` is `VARCHAR(64)`.
     */
    private const MAXIMUM_LENGTH = 64;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'A label is absent rather than blank. Omit it, or send null — a label of whitespace is a value '
                .'the platform would store and the user could not see.'
            );
        }

        if (preg_match('/[\p{C}]/u', $trimmed) === 1) {
            throw new InvalidArgumentException(
                'A label carries no control character. API-131 reports an unusable contact back to the caller, '
                .'and a value carrying a line break would make one stored field present as two.'
            );
        }

        if (mb_strlen($trimmed) > self::MAXIMUM_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'A label is at most %d characters, which is what op_user_emergency_contacts.label holds.',
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
