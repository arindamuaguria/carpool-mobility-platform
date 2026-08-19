<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Evidential;

use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidenceNotRecorded;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * The one component that writes evidential records.
 *
 * `BE-105` ‡ / `BADR-09` / `DB-004` ‡ / `DB-113` ‡: no other component writes
 * them. `BE-117` requires the prohibition to be verified by static analysis;
 * `EvidentialLogRulesTest` fails the build if the table name appears anywhere
 * else, and `TC-038` ‡ makes that rule non-suppressible.
 *
 * `BE-106` ‡ / `DB-112` ‡: this opens **no transaction of its own**. It is called
 * from inside the application service's transaction, so the record and the
 * operation it evidences commit together — or neither does. `BE-047` ‡ reserves
 * opening a transaction for the application layer in any case.
 *
 * `DB-118` ‡: the account this runs under holds `SELECT` and `INSERT` on `ev_`
 * and neither `UPDATE` nor `DELETE`, so there is no statement this class could
 * be made to issue that would alter an existing record. `BE-108` ‡ is therefore
 * a property of the credential and not of this file's restraint.
 *
 * ## Why a fork is refused rather than prevented
 *
 * `SEC-105` ‡ chains each record to its predecessor, so the writer must read the
 * predecessor before it can compute the hash — and it must compute it here,
 * because `SEC-106` ‡ keeps the key out of the database. Two concurrent writers
 * reading the same predecessor would produce two records claiming the same
 * parent, and the chain would fork.
 *
 * A locking read would serialise them, and **is not available**: MySQL refuses
 * `SELECT ... FOR UPDATE` without the `UPDATE` privilege, which `DB-118` ‡
 * withholds from this account and must. The privilege that makes the log
 * append-only is the same privilege that makes the lock impossible.
 *
 * So the database decides, on a unique constraint over `previous_hash` — the
 * technique `DB-142` ‡ chooses for the idempotency registry, and for the reason
 * it gives: rejected by the database rather than by a race-prone read. The loser
 * raises, its transaction rolls back, and `FRD-FR-248` ‡ is satisfied — the
 * operation is not reported complete. `API-062` ‡ makes the retry safe.
 *
 * A single chain is inherently serial, and `DB-201` names this table as one of
 * three that grow without bound. Contention is a real cost of `SADR-07` and is
 * stated rather than engineered around.
 */
final class DatabaseEvidentialWriter implements RecordsEvidence
{
    public const TABLE = 'ev_evidential_records';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly KeyedChainHash $chain,
    ) {}

    public function record(Evidence $evidence): void
    {
        $previousHash = $this->latestHash();

        try {
            $this->connection->insert(
                'INSERT INTO '.self::TABLE
                .' (actor, action, subject, outcome, reason, occurred_at, previous_hash, record_hash, chain_algorithm)'
                .' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $evidence->actor()->toString(),
                    $evidence->action(),
                    $evidence->subject(),
                    $evidence->outcome()->value,
                    $evidence->reason(),
                    $evidence->occurredAt()->toDatabaseString(),
                    $previousHash,
                    $this->chain->forRecord($evidence, $previousHash),
                    KeyedChainHash::ALGORITHM,
                ],
            );
        } catch (UniqueConstraintViolationException $forked) {
            // Another writer chained to the same predecessor first. The
            // constraint refused the fork, which is what it is for.
            //
            // FRD-FR-248 ‡: the operation must not be reported complete. Raising
            // rolls its transaction back, and API-062 ‡ makes the caller's retry
            // — with the same idempotency key — safe.
            throw new EvidenceNotRecorded(
                sprintf(
                    'FRD-FR-248 ‡: another record chained to the same predecessor while the record for "%s" '
                    .'was being written. The operation did not stand; retry it with the same idempotency key.',
                    $evidence->action(),
                ),
                previous: $forked,
            );
        } catch (Throwable $failure) {
            // FRD-FR-248 ‡: an action is not reported complete where its record
            // cannot be written. Raised, never swallowed — the transaction rolls
            // back and the operation does not stand.
            throw new EvidenceNotRecorded(
                sprintf('FRD-FR-248 ‡: the evidential record for "%s" could not be written.', $evidence->action()),
                previous: $failure,
            );
        }
    }

    /**
     * The most recent record's hash, or the genesis value where there is none.
     *
     * `DB-111` ‡ / `SEC-109` ‡: ordered by the monotonic, never-reused key.
     *
     * A plain read, not a locking one. `DB-118` ‡ withholds `UPDATE` from this
     * account and MySQL refuses `SELECT ... FOR UPDATE` without it — so the
     * privilege that makes the log append-only is the same privilege that makes a
     * lock unavailable. The unique constraint on `previous_hash` decides instead.
     */
    private function latestHash(): string
    {
        /** @var list<object{record_hash: string}> $rows */
        $rows = $this->connection->select(
            'SELECT record_hash FROM '.self::TABLE.' ORDER BY id DESC LIMIT 1'
        );

        return $rows === [] ? $this->chain->genesis() : $rows[0]->record_hash;
    }
}
