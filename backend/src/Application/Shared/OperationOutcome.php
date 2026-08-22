<?php

declare(strict_types=1);

namespace Cmp\Application\Shared;

/**
 * What a state-changing operation produced — in two forms, because they are not
 * always the same thing.
 *
 * `API-062` ‡ replays a recorded outcome, and `DB-143` is where it is recorded.
 * `SEC-038` ‡ keeps a session token out of **every stored and logged value**. A
 * session refresh produces a token and must therefore hand the caller something
 * the registry may not keep — so an operation says both what it is answering
 * with and what may be written down, and the difference is exactly the one
 * `SEC-038` ‡ draws.
 *
 * ## Why this is not solved by returning less
 *
 * A refresh that answered without its token would be a refresh the client could
 * not use. A refresh that recorded its token would put a live credential in a
 * table `DB-125` ‡ makes append-only — unremovable, for the value most worth
 * stealing. Neither is acceptable, and one return value cannot be both.
 *
 * ## What a replay then yields
 *
 * The **recorded** form, because that is all the platform kept. A replayed
 * refresh therefore says the refresh happened and carries no token, which is
 * correct rather than a shortfall: `API-064` requires a client to be able to
 * tell a replay from a fresh outcome, and a client that finds no token in one
 * knows to refresh again under a new key. `BADR-08` accepted that a replay
 * returns *the recorded outcome*, not a re-execution.
 */
final class OperationOutcome
{
    /**
     * @param  array<string, mixed>  $toCaller
     * @param  array<string, mixed>|null  $toRegistry
     */
    private function __construct(
        private readonly array $toCaller,
        private readonly ?array $toRegistry,
    ) {}

    /**
     * The ordinary case: what the caller gets is what may be recorded.
     *
     * @param  array<string, mixed>  $representation
     */
    public static function of(array $representation): self
    {
        return new self($representation, $representation);
    }

    /**
     * The caller gets more than the registry may keep.
     *
     * The second argument is what `DB-143` stores, and it is stated explicitly
     * rather than derived by stripping keys — a redaction rule applied here
     * would be a second place for `SEC-038` ‡ to be got wrong, and the operation
     * that produced the value is the only thing that knows which part of it is a
     * credential.
     *
     * @param  array<string, mixed>  $toCaller
     * @param  array<string, mixed>  $toRegistry
     */
    public static function withheldFromTheRegistry(array $toCaller, array $toRegistry): self
    {
        return new self($toCaller, $toRegistry);
    }

    /**
     * @return array<string, mixed>
     */
    public function toCaller(): array
    {
        return $this->toCaller;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toRegistry(): ?array
    {
        return $this->toRegistry;
    }
}
