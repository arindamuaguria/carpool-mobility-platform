<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Idempotency;

/**
 * An entry already recorded in the idempotency registry.
 */
final class RegistryEntry
{
    /**
     * @param  array<string, mixed>|null  $outcome
     */
    public function __construct(
        private readonly string $contentFingerprint,
        private readonly bool $completed,
        private readonly ?array $outcome,
    ) {}

    public function matches(string $contentFingerprint): bool
    {
        // Constant-time comparison: the fingerprint is derived from a caller's
        // own request, but comparing it in constant time costs nothing and
        // removes a timing channel by construction.
        return hash_equals($this->contentFingerprint, $contentFingerprint);
    }

    /**
     * Whether the guarded work finished and its outcome was recorded.
     *
     * An entry claimed but never completed means the transaction that claimed it
     * did not commit — in which case the row is not there at all — or that the
     * outcome write is still in flight in the same transaction.
     */
    public function completed(): bool
    {
        return $this->completed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function outcome(): ?array
    {
        return $this->outcome;
    }
}
