<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Schema;

use Cmp\Application\Shared\Schema\CheckConstraintsNotEnforced;
use Cmp\Application\Shared\Schema\VerifiesCheckConstraintEnforcement;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Proves that the deployed server **enforces** `CHECK` constraints, by
 * attempting a write that violates one and requiring the server to refuse.
 *
 * `OPS-024` ‡ requires every environment to deploy a database version that
 * enforces `CHECK`. `DB-217` ‡ / `TC-047` ‡ require migration to verify it **by
 * attempting a violating write** — because some servers accept and silently
 * ignore a `CHECK` clause, and a constraint that parses but does not enforce
 * looks identical in the schema.
 *
 * Fourteen of the twenty-one constraints in the CMP-DOC-11 §15 register are
 * database-enforced rules that hold even if the application is wrong. If `CHECK`
 * is inert, they are decoration.
 *
 * The probe creates its own table, uses it and drops it. It touches no domain
 * table and leaves nothing behind.
 */
final class CheckConstraintEnforcementProbe implements VerifiesCheckConstraintEnforcement
{
    private const PROBE_TABLE = 'mch_check_enforcement_probes';

    public function __construct(private readonly ConnectionInterface $connection) {}

    /**
     * @throws CheckConstraintsNotEnforced when the server accepts a violating write
     */
    public function verify(): void
    {
        $this->dropProbeTable();

        $this->connection->statement(
            'CREATE TABLE '.self::PROBE_TABLE.' ('
            .'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            .'value INT NOT NULL, '
            .'CONSTRAINT '.self::PROBE_TABLE.'_check_value_is_positive CHECK (value > 0)'
            .') ENGINE=InnoDB DEFAULT CHARSET='.SchemaConventions::CHARACTER_SET
            .' COLLATE='.SchemaConventions::COLLATION
        );

        $refused = false;

        try {
            $this->connection->insert('INSERT INTO '.self::PROBE_TABLE.' (value) VALUES (?)', [-1]);
        } catch (Throwable) {
            $refused = true;
        } finally {
            $this->dropProbeTable();
        }

        if (! $refused) {
            throw new CheckConstraintsNotEnforced(
                'OPS-024 ‡ / DB-217 ‡: the deployed database accepted a write violating a CHECK constraint. '
                .'Fourteen of the twenty-one constraints in the CMP-DOC-11 §15 register rely on CHECK being '
                .'enforced; on this server they would be decoration. Deploy a server that enforces CHECK '
                .'(MySQL 8.0.16 or later).'
            );
        }
    }

    private function dropProbeTable(): void
    {
        $this->connection->statement('DROP TABLE IF EXISTS '.self::PROBE_TABLE);
    }
}
