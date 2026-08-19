<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Base case for level-3 integration tests.
 *
 * `TC-030` ‡ / `OPS-024` ‡: an integration test runs against a **real MySQL that
 * enforces `CHECK`**, never an in-memory substitute. Nothing here configures a
 * fallback: if the database is absent, the suite fails rather than quietly
 * proving something weaker.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function connection(string $name): Connection
    {
        $connection = DB::connection($name);

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * `DB-215`: the only account holding `DDL`.
     */
    protected function migrationConnection(): Connection
    {
        return $this->connection('mysql_migration');
    }

    /**
     * The platform's runtime identity — deliberately unable to do most of what
     * these tests attempt.
     */
    protected function applicationConnection(): Connection
    {
        return $this->connection('mysql');
    }

    protected function readConnection(): Connection
    {
        return $this->connection('mysql_read');
    }
}
