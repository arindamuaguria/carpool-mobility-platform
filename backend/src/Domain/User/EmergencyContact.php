<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

/**
 * A person the user wants informed if something goes wrong.
 *
 * `FRD-FR-184` records the nomination against the user; `BRD-DATA-012` is the
 * business statement behind it. The contact is **not a user** — they have no
 * account, they have not agreed to anything, and `UC-OQ-006` records that
 * whether they are even told they have been nominated is undecided.
 *
 * ## This type sends nothing, and cannot be made to
 *
 * `FRD-GAP-020` blocks *"whether and when emergency contacts are informed"* on
 * `BAD-DEC-011`, and `UC-048` accepts holding a contact only *"because nothing
 * is sent to them until that definition exists."* So this carries a number and a
 * label and offers **no method that reaches anybody** — no `notify()`, no
 * `contactableBy()`, no channel. `EmergencyContactSurfaceTest` asserts that
 * surface, so the prohibition is structural rather than remembered: a future
 * change that added a way to reach a contact fails the build before it fails
 * `FRD-FR-195` ‡.
 */
final class EmergencyContact
{
    private function __construct(
        private readonly EmergencyContactReference $reference,
        private readonly PhoneNumber $number,
        private readonly ?ContactLabel $label,
    ) {}

    public static function of(
        EmergencyContactReference $reference,
        PhoneNumber $number,
        ?ContactLabel $label = null,
    ): self {
        return new self($reference, $number, $label);
    }

    public function reference(): EmergencyContactReference
    {
        return $this->reference;
    }

    public function number(): PhoneNumber
    {
        return $this->number;
    }

    public function label(): ?ContactLabel
    {
        return $this->label;
    }

    /**
     * The same contact, amended.
     *
     * `FRD-FR-182` amends a nomination; the reference is what makes it the same
     * nomination rather than a new one, so it is carried forward and the details
     * are replaced. `FRD-FR-024`'s principle — a rejected amendment leaves the
     * stored value unchanged — is served by this returning a new instance: the
     * caller's copy is only written where the whole amendment succeeded.
     */
    public function amendedTo(PhoneNumber $number, ?ContactLabel $label): self
    {
        return new self($this->reference, $number, $label);
    }
}
