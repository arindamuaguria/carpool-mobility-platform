<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Domain\User\NominatedContacts;

/**
 * The whole set, as anything outside the Domain may see it.
 *
 * `UC-048` A1 makes the set the thing a user manages, so it is the thing the
 * read answers with. A distinct type rather than a bare `list<ContactView>`
 * because the controller has to be able to ask *"is this what the read
 * returns?"* — and `list` is not something an `instanceof` can check, which
 * would leave the adapter trusting a shape instead of a type.
 *
 * The order is the repository's — `created_at` ascending, the order the user
 * built the set in — and is not re-sorted here. There is deliberately no
 * priority: `BE-195` ‡ would notify *"through the highest-priority family"*, and
 * `FRD-GAP-020` blocks that on `BAD-DEC-011`, so an order presented as
 * significant would be the first half of a mechanism whose second half must not
 * exist.
 */
final class ContactSetView
{
    /**
     * @param  list<ContactView>  $contacts
     */
    private function __construct(private readonly array $contacts) {}

    public static function of(NominatedContacts $contacts): self
    {
        return new self(array_map(ContactView::of(...), $contacts->all()));
    }

    /**
     * @return list<array{id: string, phone_number: string, label: string|null}>
     */
    public function toArray(): array
    {
        return array_map(static fn (ContactView $view): array => $view->toArray(), $this->contacts);
    }
}
