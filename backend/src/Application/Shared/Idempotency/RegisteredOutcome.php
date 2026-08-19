<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Idempotency;

/**
 * The outcome a registry entry records, so that a replay returns the original
 * rather than re-executing (`DB-143`, `API-062` ‡).
 *
 * `API-064`: a replayed response is indicated as a replay, so that a client does
 * not treat it as a fresh outcome — {@see IdempotentOperation} marks it.
 */
final class RegisteredOutcome
{
    /**
     * @param  array<string, mixed>|null  $representation
     */
    public function __construct(
        private readonly ?array $representation,
        private readonly bool $replayed = false,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function representation(): ?array
    {
        return $this->representation;
    }

    /** `API-064`. */
    public function replayed(): bool
    {
        return $this->replayed;
    }

    public function asReplay(): self
    {
        return new self($this->representation, true);
    }
}
