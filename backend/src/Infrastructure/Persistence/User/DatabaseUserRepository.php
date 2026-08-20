<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\User;

use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\AccountState;
use Cmp\Domain\User\PhoneNumber;
use Cmp\Domain\User\User;
use Cmp\Domain\User\UserReference;
use Cmp\Domain\User\UserRepository;
use Cmp\Domain\User\VerificationStanding;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * `op_users`, behind the Domain's contract.
 *
 * `BE-087` places the ORM in a repository implementation, and this uses none:
 * `Illuminate\Database\ConnectionInterface` is the query layer and every value is
 * bound (`DB-038` ‡, `SADR-09`). `BE-047` ‡: no transaction is opened here.
 *
 * ## The account state column is read and never written
 *
 * `BAD-RULE-010` permits a `SUSPENDED` or `DEACTIVATED` account to regain an
 * active session only *"through a defined account-state transition"*, and none is
 * defined — `FRD-GAP-024` is Critical and open on `BAD-DEC-006` and
 * `BAD-DEC-016`. {@see User} therefore offers no method that changes it, and
 * {@see save()} writes the column only on insert, where the state is
 * `FRD-FR-006`'s and the aggregate chose it.
 *
 * An update that wrote `account_state` would be a transition path reachable
 * without the decision that defines one — the exact thing `BE-012` says to keep
 * out of reach. So the update below names two columns and that is not one of them.
 *
 * ## An unrecognised stored value raises
 *
 * `DB-039`/`DB-045` make both state columns strings so that the value set can
 * change without a migration, and `DB-214` ‡ forbids a `CHECK` encoding the
 * business rule. The consequence is that the database will hold whatever is
 * written to it, so the Domain enum is the only thing that can refuse an
 * unrecognised value — and it refuses here, on the way in, rather than letting a
 * `User` exist in a state `BAD-RULE-010` does not define.
 */
final class DatabaseUserRepository implements UserRepository
{
    public const TABLE = 'op_users';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
    ) {}

    public function forReference(UserReference $reference): ?User
    {
        return $this->firstMatching('external_id = ?', [$reference->toString()]);
    }

    public function forPhoneNumber(PhoneNumber $phoneNumber): ?User
    {
        // Constraint 22 (op_users_phone_number_unique) makes this single-valued,
        // which is FRD-FR-004 and the ratified FRD-FR-013 reading enforced by the
        // database rather than by a query that hopes.
        return $this->firstMatching('phone_number = ?', [$phoneNumber->toString()]);
    }

    public function save(User $user): void
    {
        $existing = $this->connection->select(
            'SELECT id FROM '.self::TABLE.' WHERE external_id = ?',
            [$user->reference()->toString()],
        );

        $now = $this->clock->now()->toDatabaseString();

        if ($existing === []) {
            $this->connection->insert(
                'INSERT INTO '.self::TABLE
                .' (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
                .' VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $user->reference()->toString(),
                    $user->phoneNumber()->toString(),
                    $user->verificationStanding()->value,
                    $user->accountState()->value,
                    $now,
                    $now,
                ],
            );

            return;
        }

        // Verification standing is the one thing about an account that changes,
        // and FRD-FR-008 is the only thing that changes it. account_state and
        // phone_number are absent from this statement deliberately — see the
        // class note.
        $this->connection->update(
            'UPDATE '.self::TABLE.' SET verification_standing = ?, updated_at = ? WHERE external_id = ?',
            [$user->verificationStanding()->value, $now, $user->reference()->toString()],
        );
    }

    /**
     * @param  list<string>  $bindings
     */
    private function firstMatching(string $where, array $bindings): ?User
    {
        /** @var list<object{external_id: string, phone_number: string, verification_standing: string, account_state: string}> $rows */
        $rows = $this->connection->select(
            'SELECT external_id, phone_number, verification_standing, account_state'
            .' FROM '.self::TABLE.' WHERE '.$where,
            $bindings,
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return User::reconstitute(
            UserReference::fromString($row->external_id),
            PhoneNumber::fromString($row->phone_number),
            $this->standing($row->verification_standing),
            $this->state($row->account_state),
        );
    }

    private function standing(string $stored): VerificationStanding
    {
        return VerificationStanding::tryFrom($stored) ?? throw new RuntimeException(sprintf(
            'BAD-RULE-006: "%s" is not a verification standing. The vocabulary is UNVERIFIED and VERIFIED, '
            .'and DB-039 makes the column a string so the set can change without a migration — which means '
            .'this enum is the only thing that can refuse a value nobody defined.',
            $stored,
        ));
    }

    private function state(string $stored): AccountState
    {
        return AccountState::tryFrom($stored) ?? throw new RuntimeException(sprintf(
            'BAD-RULE-010: "%s" is not an account state. The states are ACTIVE, SUSPENDED and DEACTIVATED. '
            .'Refusing here is SEC-055 ‡\'s reasoning applied to stored data: an account in a state nothing '
            .'defines is not permitted to act, rather than permitted by default.',
            $stored,
        ));
    }
}
