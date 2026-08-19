<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Idempotency;

use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Application\Shared\Transaction\TransactionBoundary;

/**
 * Runs a state-changing operation exactly once per key.
 *
 * `BADR-08` / `AADR-04`: every state-changing application service accepts an
 * idempotency key; the registry records key, operation, outcome and completion;
 * a repeat with the same key returns the recorded outcome without re-executing.
 *
 * The whole of it happens inside **one** transaction (`BE-051` ‡, `DB-141` ‡,
 * `API-061` ‡): the claim, the work and the outcome record commit together. A
 * registry write outside that scope was considered and rejected in `BADR-08` —
 * a crash between the effect and the write would permit a duplicate.
 *
 * The claim is an insert against a unique constraint, so two concurrent requests
 * carrying the same key cannot both proceed whatever the interleaving
 * (`DB-142` ‡). Nothing here reads-then-writes.
 *
 * `BE-050` ‡: the work runs inside the transaction, so it must not call an
 * external provider. A service needing a provider result obtains it **before**
 * calling this (`BE-052`).
 */
final class IdempotentOperation
{
    public function __construct(
        private readonly TransactionBoundary $transaction,
        private readonly IdempotencyRegistry $registry,
    ) {}

    /**
     * @param  string  $operation  the operation the key is scoped to (`API-060`)
     * @param  callable(): Result  $work  its success value is an
     *                                    array<string, mixed> or null — the
     *                                    representation `DB-143` records
     */
    public function execute(
        StateChangingCommand $command,
        string $operation,
        ActorReference $actor,
        callable $work,
    ): Result {
        $key = $command->idempotencyKey();
        $fingerprint = $command->contentFingerprint();

        return $this->transaction->transactional(
            function () use ($actor, $operation, $key, $fingerprint, $work): Result {
                if (! $this->registry->claim($actor, $operation, $key, $fingerprint)) {
                    return $this->replayOrRefuse($actor, $operation, $key, $fingerprint);
                }

                $result = $work();

                if ($result->isFailure()) {
                    // API-062 ‡ speaks of returning the original outcome; a
                    // refusal is an outcome and is recorded, so that a retry
                    // receives the same answer rather than re-running the work.
                    $this->registry->recordOutcome($actor, $operation, $key, null);

                    return Result::failed($result->failure());
                }

                /** @var array<string, mixed>|null $representation */
                $representation = $result->value();
                $this->registry->recordOutcome($actor, $operation, $key, $representation);

                return Result::success(new RegisteredOutcome($representation));
            }
        );
    }

    private function replayOrRefuse(
        ActorReference $actor,
        string $operation,
        IdempotencyKey $key,
        string $fingerprint,
    ): Result {
        $entry = $this->registry->existing($actor, $operation, $key);

        if ($entry === null || ! $entry->matches($fingerprint)) {
            // API-063 ‡: a repeated key with different content is refused and
            // does not overwrite the original outcome. A missing entry is treated
            // the same way: the claim was refused by the unique constraint, so
            // something holds the key, and re-running the work would risk the
            // duplicate the registry exists to prevent.
            return Result::failed(
                new BusinessRefused(IdempotencyRefusal::KeyReusedWithDifferentContent)
            );
        }

        // API-062 ‡ / API-064: the original outcome, marked as a replay so the
        // client does not treat it as fresh.
        return Result::success(new RegisteredOutcome($entry->outcome(), replayed: true));
    }
}
