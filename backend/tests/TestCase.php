<?php

declare(strict_types=1);

namespace Tests;

use Cmp\Infrastructure\Persistence\Idempotency\DatabaseIdempotencyRegistry;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base case for tests that need the framework booted — levels 3, 4 and 5.
 *
 * Domain tests (level 2) must not extend this. `TC-029` requires a domain test
 * to run without a database, a framework or a network; those extend
 * PHPUnit\Framework\TestCase directly.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Hands every database connection back before the application is discarded.
     *
     * `DADR-09` gives the platform four connections — application, migration,
     * read and provisioning — and a level-3 or level-5 test typically opens
     * several. The framework builds a fresh application per test and does not
     * close what the previous one opened, so the suite accumulated live
     * connections until MySQL refused with `1040 Too many connections`.
     *
     * That is a property of the harness rather than of the platform, but it
     * failed the build all the same, and it would have failed it sooner on a
     * server configured with a smaller `max_connections` — so it is closed here
     * rather than worked around by running fewer tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearTheIdempotencyRegistry();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $resolver = $this->app->make(ConnectionResolverInterface::class);

            foreach (array_keys((array) config('database.connections')) as $name) {
                if (is_string($name)) {
                    $resolver->connection($name)->disconnect();
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Puts `API-062` ‡'s registry back to empty before each test.
     *
     * The registry is platform state like any other, and it outlives a test —
     * `API-060` scopes a key to an actor and an operation, and nothing scopes it
     * to a test run. Two tests in one class that both call an operation under
     * the same key would otherwise have the second **replayed**: it would pass
     * or fail for a reason that had nothing to do with what it was asserting.
     *
     * That is not hypothetical. It is what `CC-045`'s wiring exposed the moment
     * the registry began doing its job, and it is a fixture problem rather than a
     * platform one — so it is fixed here once rather than by giving every test a
     * key nobody else uses, which is a discipline a later test would forget.
     *
     * `DB-215`: the migration account is the one that may clear a table.
     */
    private function clearTheIdempotencyRegistry(): void
    {
        if ($this->app === null) {
            return;
        }

        $this->app->make(ConnectionResolverInterface::class)
            ->connection('mysql_migration')
            ->delete('DELETE FROM '.DatabaseIdempotencyRegistry::TABLE);
    }
}
