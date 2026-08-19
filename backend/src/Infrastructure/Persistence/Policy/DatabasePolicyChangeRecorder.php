<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Policy;

use Cmp\Application\Shared\Policy\PolicyChangeConflicted;
use Cmp\Application\Shared\Policy\RecordsPolicyChanges;
use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Time\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Writes policy versions to `cfg_policy_values` and `cfg_policy_versions`.
 *
 * `DB-152`: a change appends a version. **Nothing here updates anything** — the
 * application account holds no `UPDATE` on `cfg_`, so an in-place change is not
 * merely avoided by discipline but refused by the server.
 *
 * Every method runs inside the caller's transaction (`BE-047` ‡ reserves opening
 * one for the application layer), so the declaration and the version that makes
 * it meaningful commit together.
 */
final class DatabasePolicyChangeRecorder implements RecordsPolicyChanges
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Clock $clock,
    ) {}

    public function declare(PolicyKey $key): void
    {
        if ($this->declarationId($key) !== null) {
            return;
        }

        $this->connection->insert(
            'INSERT INTO '.DatabasePolicyStore::VALUES_TABLE
            .' (policy_key, value_type, created_at) VALUES (?, ?, ?)',
            [$key->name(), $key->type()->value, $this->clock->now()->toDatabaseString()],
        );
    }

    public function currentRawValue(PolicyKey $key): ?string
    {
        /** @var list<object{value_text: string}> $rows */
        $rows = $this->connection->select(
            'SELECT v.value_text FROM '.DatabasePolicyStore::VALUES_TABLE.' AS pv'
            .' INNER JOIN '.DatabasePolicyStore::VERSIONS_TABLE.' AS v ON v.policy_value_id = pv.id'
            .' WHERE pv.policy_key = ? ORDER BY v.version DESC LIMIT 1',
            [$key->name()],
        );

        return $rows === [] ? null : $rows[0]->value_text;
    }

    public function appendVersion(
        PolicyKey $key,
        string $newRawValue,
        ?string $previousRawValue,
        string $actor,
    ): int {
        $declarationId = $this->declarationId($key)
            ?? throw new RuntimeException(sprintf('"%s" was not declared before a version was appended.', $key->name()));

        /** @var list<object{highest: int|string|null}> $rows */
        $rows = $this->connection->select(
            'SELECT MAX(version) AS highest FROM '.DatabasePolicyStore::VERSIONS_TABLE
            .' WHERE policy_value_id = ?',
            [$declarationId],
        );

        $version = ((int) ($rows[0]->highest ?? 0)) + 1;

        try {
            $this->connection->insert(
                'INSERT INTO '.DatabasePolicyStore::VERSIONS_TABLE
                .' (policy_value_id, version, value_text, previous_value_text, applied_by, applied_at)'
                .' VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $declarationId,
                    $version,
                    $newRawValue,
                    $previousRawValue,
                    $actor,
                    $this->clock->now()->toDatabaseString(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            // Someone else took this version number. The constraint decided, not
            // a read — DB-142 ‡ makes the same choice for the idempotency
            // registry, and for the same reason.
            throw PolicyChangeConflicted::forKey($key);
        }

        return $version;
    }

    private function declarationId(PolicyKey $key): ?int
    {
        return $this->idFrom(
            'SELECT id FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
            $key,
        );
    }

    private function idFrom(string $query, PolicyKey $key): ?int
    {
        /** @var list<object{id: int|string}> $rows */
        $rows = $this->connection->select($query, [$key->name()]);

        return $rows === [] ? null : (int) $rows[0]->id;
    }
}
