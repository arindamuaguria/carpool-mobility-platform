<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Idempotency;

use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Idempotency\IdempotencyRegistry;
use Cmp\Application\Shared\Idempotency\RegistryEntry;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use JsonException;
use RuntimeException;

/**
 * The idempotency registry over `mch_idempotency_registries`.
 *
 * `DB-142` ‡ is the load-bearing part: the claim is an `INSERT` against a unique
 * constraint on actor, operation and key, so a duplicate is rejected **by the
 * database** rather than by a race-prone read. A `SELECT` followed by an
 * `INSERT` would leave a window in which two requests both find nothing.
 *
 * Every method runs inside the caller's transaction (`DB-141` ‡, `BE-051` ‡):
 * this class opens none of its own, and `BE-047` ‡ reserves that for the
 * application layer.
 *
 * `DB-013`: no foreign key from `mch_` into `op_`. The actor is held as a
 * recorded reference, not as a relation.
 */
final class DatabaseIdempotencyRegistry implements IdempotencyRegistry
{
    public const TABLE = 'mch_idempotency_registries';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly int $interfaceVersion,
    ) {}

    public function claim(
        ActorReference $actor,
        string $operation,
        IdempotencyKey $key,
        string $contentFingerprint,
    ): bool {
        try {
            $this->connection->insert(
                'INSERT INTO '.self::TABLE
                .' (actor, operation, request_key, content_fingerprint, interface_version, claimed_at)'
                .' VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $actor->toString(),
                    $operation,
                    $key->toString(),
                    $contentFingerprint,
                    $this->interfaceVersion,
                    $this->now(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // DB-142 ‡: the duplicate is rejected by the constraint. This is the
            // expected path for a repeated request, not an error.
            return false;
        }

        return true;
    }

    public function existing(ActorReference $actor, string $operation, IdempotencyKey $key): ?RegistryEntry
    {
        /** @var list<object{content_fingerprint: string, outcome: ?string, completed_at: ?string}> $rows */
        $rows = $this->connection->select(
            'SELECT content_fingerprint, outcome, completed_at FROM '.self::TABLE
            .' WHERE actor = ? AND operation = ? AND request_key = ?',
            [$actor->toString(), $operation, $key->toString()],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return new RegistryEntry(
            $row->content_fingerprint,
            $row->completed_at !== null,
            $this->decode($row->outcome),
        );
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
        $this->connection->update(
            'UPDATE '.self::TABLE.' SET outcome = ?, completed_at = ?'
            .' WHERE actor = ? AND operation = ? AND request_key = ?',
            [
                $representation === null ? null : $this->encode($representation),
                $this->now(),
                $actor->toString(),
                $operation,
                $key->toString(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $representation
     */
    private function encode(array $representation): string
    {
        try {
            return json_encode($representation, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'DB-143: an outcome must be recordable, or a replay cannot return the original.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(?string $outcome): ?array
    {
        if ($outcome === null) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($outcome, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * `DB-034`: the instant, in one time zone reference — never a local
     * wall-clock reading.
     */
    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
