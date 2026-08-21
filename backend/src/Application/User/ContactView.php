<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Domain\User\EmergencyContact;

/**
 * A nominated contact as anything outside the Domain may see it.
 *
 * `BE-002` keeps a Domain type out of the `Interface` layer, and the layer graph
 * rejected an earlier version of `EmergencyContactController` that named
 * {@see EmergencyContact} directly — the same violation FEAT-032 hit, fixed the
 * same way rather than by relaxing the rule.
 *
 * It is not only a layer formality. `API-050` ‡ puts **what a representation
 * discloses** outside the adapter, and this is where that decision now lives:
 * three fields, chosen here, and a controller that serialises what it is given
 * rather than choosing for itself.
 *
 * ## Three fields, and nothing derived
 *
 * The reference, the number and the label. `DB-024` ‡: the identifier is the
 * external one and the internal key never leaves the repository. `API-036` ‡:
 * no field by which a caller could assert something the platform decides.
 *
 * And **nothing about whether anybody would be reached** — no channel, no
 * reachability, no priority. `FRD-GAP-020` blocks every part of informing a
 * contact on `BAD-DEC-011`, and a field here suggesting otherwise would be
 * `FRD-FR-195` ‡'s prohibition broken in the one place a user would read it.
 */
final class ContactView
{
    private function __construct(
        private readonly string $id,
        private readonly string $phoneNumber,
        private readonly ?string $label,
    ) {}

    public static function of(EmergencyContact $contact): self
    {
        return new self(
            $contact->reference()->toString(),
            $contact->number()->toString(),
            $contact->label()?->toString(),
        );
    }

    /**
     * The label is served as `null` rather than omitted.
     *
     * A client reads a field it can rely on being there; omitting it would make
     * *"this contact has no label"* and *"the server did not mention labels"*
     * the same value.
     *
     * @return array{id: string, phone_number: string, label: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'phone_number' => $this->phoneNumber,
            'label' => $this->label,
        ];
    }
}
