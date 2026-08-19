<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Evidential;

use Cmp\Application\Shared\Evidence\ChainVerification;
use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\VerifiesEvidentialChain;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Domain\Shared\Time\Instant;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Re-derives the chain and reports the first divergence.
 *
 * `BE-115` / `DB-115` / `SEC-111`: it reports; `SEC-112` ‡ and `DB-115` are
 * explicit that it never repairs. *"A break is a finding, not a fault to
 * correct."* There is no write path in this class at all, and the account it
 * runs under could not take one.
 *
 * It runs under the **read** account (`DADR-09`), which holds `SELECT` and
 * nothing else. A verifier that could alter what it verifies would be worth very
 * little, and this one cannot.
 *
 * Three ways a record fails:
 *
 * - its recorded previous hash is not its actual predecessor's hash — a record
 *   was removed, inserted, or reordered;
 * - its own hash is not what its content and predecessor produce — its content
 *   was altered;
 * - it was written under a construction this verifier does not know — which
 *   `SEC-174` anticipates, and which is a finding rather than a pass.
 *
 * `SEC-114` and `SEC-115` ‡ — incremental verification anchored on keyed
 * checkpoints — are **not implemented**, and `VerifiesEvidentialChain` records
 * why: CMP-DOC-11 specifies one table in `ev_`, and a checkpoint needs somewhere
 * to live.
 */
final class DatabaseEvidentialChainVerifier implements VerifiesEvidentialChain
{
    /**
     * Read in pages so that a growing log does not have to fit in memory.
     * `DB-201` names this table as one of three that grow monotonically.
     */
    private const PAGE = 500;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly KeyedChainHash $chain,
    ) {}

    public function verify(?int $fromRecordId = null): ChainVerification
    {
        // Null verifies from the beginning, which is the only pass that needs no
        // trusted starting point. Otherwise the anchor is the hash of whatever
        // precedes the requested record.
        $afterId = $fromRecordId === null ? 0 : $fromRecordId - 1;
        $expectedPreviousHash = $fromRecordId === null
            ? $this->chain->genesis()
            : ($this->recordBefore($fromRecordId) ?? $this->chain->genesis());

        $verified = 0;

        while (true) {
            $rows = $this->page($afterId);

            if ($rows === []) {
                return ChainVerification::intact($verified);
            }

            foreach ($rows as $row) {
                $id = (int) $row->id;
                $afterId = $id;
                $verified++;

                if (! hash_equals($expectedPreviousHash, $row->previous_hash)) {
                    return ChainVerification::diverged(
                        $verified,
                        $id,
                        'its recorded predecessor is not the record that precedes it — '
                        .'a record was removed, inserted or reordered',
                    );
                }

                if ($row->chain_algorithm !== KeyedChainHash::ALGORITHM) {
                    return ChainVerification::diverged(
                        $verified,
                        $id,
                        sprintf(
                            'it was written under construction "%s", which this verifier cannot re-derive '
                            .'(SEC-174: a replacement must be staged so that both can be verified)',
                            $row->chain_algorithm,
                        ),
                    );
                }

                try {
                    $evidence = $this->evidenceFrom($row);
                } catch (InvalidArgumentException $malformed) {
                    // A record that is not a well-formed Evidence cannot have been
                    // written by the writer, which builds one before it inserts.
                    // SEC-112 ‡: report it; do not repair it, and do not raise —
                    // a verification pass that threw on a tampered record would
                    // stop before reporting the tampering.
                    return ChainVerification::diverged(
                        $verified,
                        $id,
                        'its content is not a well-formed record: '.$malformed->getMessage(),
                    );
                }

                if (! $this->chain->matches($evidence, $row->previous_hash, $row->record_hash)) {
                    return ChainVerification::diverged(
                        $verified,
                        $id,
                        'its content does not produce its recorded hash — the record was altered',
                    );
                }

                $expectedPreviousHash = $row->record_hash;
            }
        }
    }

    /**
     * @return list<object{id: int|string, actor: string, action: string, subject: string, outcome: string, reason: ?string, occurred_at: string, previous_hash: string, record_hash: string, chain_algorithm: string}>
     */
    private function page(int $afterId): array
    {
        /** @var list<object{id: int|string, actor: string, action: string, subject: string, outcome: string, reason: ?string, occurred_at: string, previous_hash: string, record_hash: string, chain_algorithm: string}> $rows */
        $rows = $this->connection->select(
            'SELECT id, actor, action, subject, outcome, reason, occurred_at, previous_hash, record_hash, chain_algorithm'
            .' FROM '.DatabaseEvidentialWriter::TABLE
            .' WHERE id > ? ORDER BY id ASC LIMIT '.self::PAGE,
            [$afterId],
        );

        return $rows;
    }

    private function recordBefore(int $recordId): ?string
    {
        /** @var list<object{record_hash: string}> $rows */
        $rows = $this->connection->select(
            'SELECT record_hash FROM '.DatabaseEvidentialWriter::TABLE
            .' WHERE id < ? ORDER BY id DESC LIMIT 1',
            [$recordId],
        );

        return $rows === [] ? null : $rows[0]->record_hash;
    }

    /**
     * @param  object{actor: string, action: string, subject: string, outcome: string, reason: ?string, occurred_at: string}  $row
     */
    private function evidenceFrom(object $row): Evidence
    {
        return Evidence::of(
            ActorReference::fromString($row->actor),
            $row->action,
            $row->subject,
            EvidentialOutcome::from($row->outcome),
            Instant::fromString($row->occurred_at),
            $row->reason,
        );
    }
}
