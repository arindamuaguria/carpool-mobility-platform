<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\User;

use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\ContactLabel;
use Cmp\Domain\User\EmergencyContact;
use Cmp\Domain\User\EmergencyContactReference;
use Cmp\Domain\User\EmergencyContactRepository;
use Cmp\Domain\User\NominatedContacts;
use Cmp\Domain\User\PhoneNumber;
use Cmp\Domain\User\UserReference;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * `op_user_emergency_contacts`, behind the Domain's contract.
 *
 * `BE-087` and `DB-038` ‡/`SADR-09` as everywhere else: the query layer, never
 * the ORM, and every value bound. `BE-047` ‡: no transaction is opened here —
 * the application service owns the boundary, and {@see save()} performs several
 * statements that must stand or fall together, so calling it outside one would
 * be the defect rather than this being wrong.
 *
 * ## The set is written as a difference
 *
 * `UC-048` A1 makes the set the unit of work, and {@see
 * EmergencyContactRepository::save()} takes the whole of it. Writing it as
 * delete-all-then-insert would be simpler and would be wrong twice over: it
 * would change the `created_at` of contacts the user did not touch, and it would
 * make every amendment a removal and a nomination as far as the row's identity
 * is concerned. So the difference is computed against what is stored, keyed by
 * the external reference.
 *
 * ## The constraint decides a race
 *
 * `DB-142` ‡'s pattern. Two requests nominating the same number reach
 * {@see NominatedContacts::with()} with the same set behind them and both pass;
 * `op_user_emergency_contacts_user_number_unique` then admits one and the other
 * rolls back with its transaction. There is no locking read here, because the
 * constraint already answers the question a lock would have been taken to ask.
 */
final class DatabaseEmergencyContactRepository implements EmergencyContactRepository
{
    public const TABLE = 'op_user_emergency_contacts';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
    ) {}

    public function forUser(UserReference $user): NominatedContacts
    {
        /** @var list<object{external_id: string, phone_number: string, label: ?string}> $rows */
        $rows = $this->connection->select(
            'SELECT c.external_id, c.phone_number, c.label'
            .' FROM '.self::TABLE.' c'
            .' INNER JOIN '.DatabaseUserRepository::TABLE.' u ON u.id = c.user_id'
            .' WHERE u.external_id = ?'
            // DB-024 ‡ keeps the internal key out of anything a caller reads, so
            // it cannot be the order either. created_at is the order the user
            // built the set in, which is the only one they would recognise.
            .' ORDER BY c.created_at ASC, c.external_id ASC',
            [$user->toString()],
        );

        return NominatedContacts::of($user, ...array_map(
            static fn (object $row): EmergencyContact => EmergencyContact::of(
                EmergencyContactReference::fromString($row->external_id),
                PhoneNumber::fromString($row->phone_number),
                $row->label === null ? null : ContactLabel::fromString($row->label),
            ),
            $rows,
        ));
    }

    public function save(NominatedContacts $contacts): void
    {
        $userId = $this->internalIdFor($contacts->user());
        $now = $this->clock->now()->toDatabaseString();

        /** @var list<object{external_id: string}> $storedRows */
        $storedRows = $this->connection->select(
            'SELECT external_id FROM '.self::TABLE.' WHERE user_id = ?',
            [$userId],
        );

        $stored = array_map(static fn (object $row): string => $row->external_id, $storedRows);
        $wanted = [];

        foreach ($contacts->all() as $contact) {
            $reference = $contact->reference()->toString();
            $wanted[] = $reference;

            if (in_array($reference, $stored, true)) {
                $this->connection->update(
                    'UPDATE '.self::TABLE.' SET phone_number = ?, label = ?, updated_at = ?'
                    .' WHERE user_id = ? AND external_id = ?',
                    [$contact->number()->toString(), $contact->label()?->toString(), $now, $userId, $reference],
                );

                continue;
            }

            $this->connection->insert(
                'INSERT INTO '.self::TABLE
                .' (external_id, user_id, phone_number, label, created_at, updated_at)'
                .' VALUES (?, ?, ?, ?, ?, ?)',
                [$reference, $userId, $contact->number()->toString(), $contact->label()?->toString(), $now, $now],
            );
        }

        foreach (array_diff($stored, $wanted) as $withdrawn) {
            // The user removed this nomination. The row goes — see
            // RemoveEmergencyContact for why deleting is the right treatment for
            // a third party's details the user has withdrawn.
            $this->connection->delete(
                'DELETE FROM '.self::TABLE.' WHERE user_id = ? AND external_id = ?',
                [$userId, $withdrawn],
            );
        }
    }

    /**
     * `DB-024` ‡ keeps the internal key out of the Domain, so the join happens
     * here and the identifier never leaves this class.
     */
    private function internalIdFor(UserReference $user): int
    {
        /** @var list<object{id: int}> $rows */
        $rows = $this->connection->select(
            'SELECT id FROM '.DatabaseUserRepository::TABLE.' WHERE external_id = ?',
            [$user->toString()],
        );

        if ($rows === []) {
            // The session resolved to this user, so the row existed a moment ago.
            // BE-186 ‡: a fault, not a refusal — nothing the caller did explains
            // it and nothing they could do would fix it.
            throw new RuntimeException(
                'FRD-FR-184 records contacts against a user, and this reference names none.'
            );
        }

        return $rows[0]->id;
    }
}
