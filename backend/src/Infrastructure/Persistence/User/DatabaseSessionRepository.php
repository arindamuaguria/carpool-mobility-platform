<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\User;

use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * `op_sessions`, behind the Domain's contract.
 *
 * `SEC-041` ‡: session state is held in the store and not in application-instance
 * memory, so nothing here caches. `SEC-042` makes validation a hash-and-lookup on
 * every request, which `op_sessions_token_hash_unique` — integrity constraint 23
 * — turns into an exact single-row read.
 *
 * `BE-047` ‡: no transaction is opened here. `save()` is called inside the one
 * the application service opened, so a terminated session and whatever else the
 * operation did commit together or not at all.
 *
 * `BE-087` places the ORM in a repository implementation, and this uses none:
 * `Illuminate\Database\ConnectionInterface` is the query layer, and every value
 * is bound (`DB-038` ‡, `SADR-09`).
 *
 * ## It writes no delete
 *
 * `DB-044` ‡ records a terminated session and does not remove it. There is no
 * statement here that could remove one, which is `DB-118` ‡'s reasoning applied
 * by construction rather than by grant: `op_` does carry the application account's
 * `DELETE`, so the absence has to be in the code.
 */
final class DatabaseSessionRepository implements SessionRepository
{
    public const TABLE = 'op_sessions';

    public function __construct(private readonly ConnectionInterface $connection) {}

    public function forTokenHash(string $tokenHash): ?Session
    {
        /** @var list<object{external_id: string, token_hash: string, established_at: string, terminated_at: ?string}> $rows */
        $rows = $this->connection->select(
            'SELECT u.external_id, s.token_hash, s.established_at, s.terminated_at'
            .' FROM '.self::TABLE.' AS s INNER JOIN op_users AS u ON u.id = s.user_id'
            .' WHERE s.token_hash = ?',
            [$tokenHash],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return Session::reconstitute(
            UserReference::fromString($row->external_id),
            $row->token_hash,
            Instant::fromString($row->established_at),
            $row->terminated_at === null ? null : Instant::fromString($row->terminated_at),
        );
    }

    public function usableCountFor(UserReference $user, Instant $now, int $lifetimeInSeconds): int
    {
        if ($lifetimeInSeconds < 1) {
            throw new RuntimeException(
                'SEC-039 ‡: a session lifetime is a positive bound, and counting against a bound of zero would '
                .'report every user as holding none.'
            );
        }

        // SEC-039 ‡ measured from establishment, in the same direction
        // Session::hasExpiredAt() measures it: elapsed >= lifetime has expired,
        // so still-usable is established_at > now - lifetime. The two are written
        // once each and SessionLifecycleTest asserts they agree.
        $earliestStillUsable = $now->toDateTime()->modify(sprintf('-%d seconds', $lifetimeInSeconds));

        /** @var list<object{total: int|string}> $rows */
        $rows = $this->connection->select(
            'SELECT COUNT(*) AS total FROM '.self::TABLE.' AS s INNER JOIN op_users AS u ON u.id = s.user_id'
            .' WHERE u.external_id = ? AND s.terminated_at IS NULL AND s.established_at > ?',
            [$user->toString(), Instant::fromDateTime($earliestStillUsable)->toDatabaseString()],
        );

        // op_sessions_user_id_index (DB-216) is what keeps this an index range
        // rather than a scan, and SEC-046's terminate-all is the other reason it
        // exists.
        return $rows === [] ? 0 : (int) $rows[0]->total;
    }

    /**
     * Writes the session, inserting it where it is new.
     *
     * The `token_hash` is the identity: constraint 23 makes it unique, so an
     * update keyed on it touches exactly one row. `established_at` is never
     * updated — a session's beginning is a fact, and `SEC-039` ‡ measures the
     * bound from it.
     */
    public function save(Session $session): void
    {
        $existing = $this->connection->select(
            'SELECT id FROM '.self::TABLE.' WHERE token_hash = ?',
            [$session->tokenHash()],
        );

        if ($existing === []) {
            $this->connection->insert(
                'INSERT INTO '.self::TABLE.' (user_id, token_hash, established_at, terminated_at) VALUES (?, ?, ?, ?)',
                [
                    $this->internalIdFor($session->user()),
                    $session->tokenHash(),
                    $session->establishedAt()->toDatabaseString(),
                    $session->terminatedAt()?->toDatabaseString(),
                ],
            );

            return;
        }

        // SEC-040 ‡ is the only change a stored session undergoes: it becomes
        // terminated. Nothing else about it can be amended, so nothing else is
        // written.
        $this->connection->update(
            'UPDATE '.self::TABLE.' SET terminated_at = ? WHERE token_hash = ?',
            [$session->terminatedAt()?->toDatabaseString(), $session->tokenHash()],
        );
    }

    /**
     * `DB-024` ‡ keeps the internal key out of everything a caller reads, and out
     * of the Domain with it — so the aggregate carries a {@see UserReference} and
     * the translation happens here, at the edge that owns the key.
     */
    private function internalIdFor(UserReference $user): int
    {
        /** @var list<object{id: int|string}> $rows */
        $rows = $this->connection->select(
            'SELECT id FROM op_users WHERE external_id = ?',
            [$user->toString()],
        );

        if ($rows === []) {
            throw new RuntimeException(
                'SEC-044 ‡: a session is bound to the actor it authenticated, and this one names an account '
                .'the store does not hold.'
            );
        }

        return (int) $rows[0]->id;
    }
}
