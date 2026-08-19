<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Idempotency\IdempotencyRegistry;
use Cmp\Application\Shared\Idempotency\RegistryEntry;

/**
 * A registry standing in for `mch_idempotency_registries` at level 2.
 *
 * It reproduces the one property the sequencing depends on: a claim already held
 * is refused, and refusing it does not disturb what is recorded. What it cannot
 * reproduce is `DB-142` ‡ — a duplicate rejected by a **unique constraint**
 * rather than by a check in code — which is exactly why that is asserted at
 * level 3 under genuine concurrency (`TADR-08`, `TC-030` ‡).
 */
final class InMemoryIdempotencyRegistry implements IdempotencyRegistry
{
    /** @var array<string, array{fingerprint: string, completed: bool, outcome: array<string, mixed>|null}> */
    private array $entries = [];

    private ?ImmediateTransactionBoundary $transaction = null;

    /**
     * Lets the double report which of its calls fell inside a transaction scope.
     */
    public function observedBy(ImmediateTransactionBoundary $transaction): void
    {
        $this->transaction = $transaction;
    }

    /**
     * Makes the double discard its writes when the surrounding scope unwinds, so
     * that `BE-053` ‡ can be asserted without a database.
     */
    public function rollBackOn(ImmediateTransactionBoundary $transaction): void
    {
        $this->observedBy($transaction);
        $snapshot = $this->entries;

        $transaction->onRollback(function () use ($snapshot): void {
            $this->entries = $snapshot;
        });
    }

    public function claim(
        ActorReference $actor,
        string $operation,
        IdempotencyKey $key,
        string $contentFingerprint,
    ): bool {
        $this->transaction?->record('claim');

        $reference = $this->reference($actor, $operation, $key->toString());

        if (array_key_exists($reference, $this->entries)) {
            return false;
        }

        $this->entries[$reference] = [
            'fingerprint' => $contentFingerprint,
            'completed' => false,
            'outcome' => null,
        ];

        return true;
    }

    public function existing(ActorReference $actor, string $operation, IdempotencyKey $key): ?RegistryEntry
    {
        $entry = $this->entries[$this->reference($actor, $operation, $key->toString())] ?? null;

        if ($entry === null) {
            return null;
        }

        return new RegistryEntry($entry['fingerprint'], $entry['completed'], $entry['outcome']);
    }

    /**
     * @param  array<string, mixed>|null  $representation
     */
    public function recordOutcome(
        ActorReference $actor,
        string $operation,
        IdempotencyKey $key,
        ?array $representation,
    ): void {
        $this->transaction?->record('recordOutcome');

        $reference = $this->reference($actor, $operation, $key->toString());

        if (! array_key_exists($reference, $this->entries)) {
            return;
        }

        $this->entries[$reference]['completed'] = true;
        $this->entries[$reference]['outcome'] = $representation;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recordedOutcome(ActorReference $actor, string $operation, string $key): ?array
    {
        return $this->entries[$this->reference($actor, $operation, $key)]['outcome'] ?? null;
    }

    private function reference(ActorReference $actor, string $operation, string $key): string
    {
        return $actor->toString().'|'.$operation.'|'.$key;
    }
}
