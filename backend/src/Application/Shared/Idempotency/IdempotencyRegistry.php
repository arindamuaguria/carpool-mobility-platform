<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Idempotency;

/**
 * The registry that makes a repeated request safe.
 *
 * `BADR-08`, `DB-141` ‡ … `DB-143`, `API-057` ‡ … `API-064`.
 *
 * The claim is an **insert**, not a read-then-write: `DB-142` ‡ requires the
 * duplicate to be rejected by the database on a unique constraint rather than by
 * a race-prone read. Two concurrent requests carrying the same key therefore
 * cannot both proceed, whatever the interleaving.
 *
 * Every method here is called **inside** the caller's transaction, so that
 * `BE-051` ‡ and `DB-141` ‡ hold: the registry entry commits with the effect it
 * guards. `BADR-08` records why the alternative was rejected — a crash between
 * the effect and a registry write outside the transaction would permit a
 * duplicate.
 */
interface IdempotencyRegistry
{
    /**
     * Claims the key for this actor and operation.
     *
     * @param  string  $contentFingerprint  a stable fingerprint of the command's
     *                                      content, so that `API-063` ‡ can tell a
     *                                      replay from a reused key
     * @return bool true where the claim was taken, false where an entry already exists
     */
    public function claim(
        ActorReference $actor,
        string $operation,
        IdempotencyKey $key,
        string $contentFingerprint,
    ): bool;

    /**
     * The entry already recorded for this actor, operation and key.
     */
    public function existing(
        ActorReference $actor,
        string $operation,
        IdempotencyKey $key,
    ): ?RegistryEntry;

    /**
     * Records the outcome against the claim, in the same transaction
     * (`DB-141` ‡, `DB-143`).
     *
     * @param  array<string, mixed>|null  $representation
     */
    public function recordOutcome(
        ActorReference $actor,
        string $operation,
        IdempotencyKey $key,
        ?array $representation,
    ): void;
}
