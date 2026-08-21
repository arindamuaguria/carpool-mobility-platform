<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use InvalidArgumentException;

/**
 * A nominated contact's identity as anything outside the database sees it.
 *
 * `DB-022` ‡: *"**Every** entity exposed through the interface shall
 * additionally carry a unique, indexed, randomly generated external
 * identifier."* A contact is addressed at
 * `/profile/emergency-contacts/{id}`, so it is exposed and the rule reaches it.
 *
 * ## Why this is a second type rather than a reuse of `UserReference`
 *
 * The two are the same **shape** and never the same **thing**. {@see
 * UserReference} exists because a bare string could be passed to the wrong
 * parameter unnoticed; a contact reference that was a `UserReference` would
 * reintroduce exactly that, and the mistake it would permit is the one that
 * matters most here — reading one user's contacts through another user's
 * identifier. The duplication is thirty lines; the alternative is a class of bug
 * no test would obviously catch.
 *
 * ## Generated outside the Domain
 *
 * There is no `generate()`, for `UserReference`'s reason: `DB-023` ‡ needs a
 * cryptographically adequate random source, and `BE-002`/`TC-029` ‡ keep the
 * Domain testable without the environment. `GeneratesContactReferences` is the
 * port and the infrastructure holds the source.
 */
final class EmergencyContactReference
{
    /**
     * `op_user_emergency_contacts.external_id` is `CHAR(32)` — 16 random bytes
     * in hexadecimal, the same form and the same entropy as every other external
     * identifier the platform issues.
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
