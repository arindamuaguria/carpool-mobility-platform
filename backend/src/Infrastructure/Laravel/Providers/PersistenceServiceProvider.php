<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Database\ProvisionsDatabaseAccounts;
use Cmp\Application\Shared\Schema\ApprovesDestructiveMigrations;
use Cmp\Application\Shared\Schema\InspectsSchemaConventions;
use Cmp\Application\Shared\Schema\SchemaConventionViolated;
use Cmp\Application\Shared\Schema\SchemaVerification;
use Cmp\Application\Shared\Schema\VerifiesCheckConstraintEnforcement;
use Cmp\Application\Shared\Schema\VerifySchema;
use Cmp\Infrastructure\Persistence\Grants\DatabaseAccount;
use Cmp\Infrastructure\Persistence\Grants\DatabaseAccountProvisioner;
use Cmp\Infrastructure\Persistence\Grants\GrantPlan;
use Cmp\Infrastructure\Persistence\Schema\CheckConstraintEnforcementProbe;
use Cmp\Infrastructure\Persistence\Schema\DestructiveMigrationGuard;
use Cmp\Infrastructure\Persistence\Schema\SchemaConventionInspector;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The composition root for persistence: binds the Application contracts of
 * CMP-DOC-11 to their MySQL implementations, and wires the checks into the
 * migration path.
 *
 * `DB-014` and `DB-216` require domain membership and the mechanical part of the
 * migration review to be checked **at migration time**; `DB-217` ‡ requires
 * migration to verify `CHECK` enforcement by attempting a violating write. That
 * makes these properties of running a migration rather than of remembering to
 * run a command. `TC-040` ‡: they run in every environment, not only in a
 * pipeline.
 */
final class PersistenceServiceProvider extends ServiceProvider
{
    /** `DB-215`: the migration account is the only account holding `DDL`. */
    public const MIGRATION_CONNECTION = 'mysql_migration';

    /** The application account's connection — the platform's runtime identity. */
    public const APPLICATION_CONNECTION = 'mysql';

    /** `DADR-09`: the read account holds `SELECT` and is used for reporting. */
    public const READ_CONNECTION = 'mysql_read';

    /** Used by `db:provision-accounts` alone (`DB-122` ‡). */
    public const PROVISIONING_CONNECTION = 'mysql_provisioning';

    public function register(): void
    {
        $this->app->bind(
            ApprovesDestructiveMigrations::class,
            static fn (Application $app): DestructiveMigrationGuard => new DestructiveMigrationGuard(
                $app->databasePath('migrations'),
                $app->databasePath('migrations/DESTRUCTIVE-APPROVALS.md'),
            ),
        );

        $this->app->bind(
            VerifiesCheckConstraintEnforcement::class,
            // The probe creates and drops its own table, so it needs DDL and
            // therefore the migration account (DB-215, DB-119 ‡).
            fn (): CheckConstraintEnforcementProbe => new CheckConstraintEnforcementProbe(
                $this->connection(self::MIGRATION_CONNECTION),
            ),
        );

        $this->app->bind(
            InspectsSchemaConventions::class,
            // Inspection is reporting, and reporting is the read account's
            // (DADR-09). It reads; it changes nothing.
            fn (): SchemaConventionInspector => new SchemaConventionInspector(
                $this->connection(self::READ_CONNECTION),
            ),
        );

        $this->app->bind(
            ProvisionsDatabaseAccounts::class,
            fn (): DatabaseAccountProvisioner => new DatabaseAccountProvisioner(
                $this->provisioningConnection(),
                new GrantPlan($this->schemaName()),
                $this->configuredAccounts(),
            ),
        );
    }

    public function boot(): void
    {
        Event::listen(MigrationsStarted::class, function (MigrationsStarted $event): void {
            $this->requireMigrationAccount($event);

            $verify = $this->app->make(VerifySchema::class);

            $this->require($verify->verifyMigrationApprovals());
            $this->require($verify->verifyCheckEnforcement());
        });

        Event::listen(MigrationsEnded::class, function (): void {
            $this->require($this->app->make(VerifySchema::class)->verifyConventions());
            $this->reapplyApplicationGrants();
        });
    }

    /**
     * `DB-215` — a migration runs under the migration account, which is the only
     * account holding `DDL`. `DB-119` ‡ makes the converse structural: the
     * application account holds no `DDL`, so it could not remove a constraint or
     * a trigger that constrains it even if a migration asked it to.
     *
     * @throws RuntimeException
     */
    private function requireMigrationAccount(MigrationsStarted $event): void
    {
        $configured = $this->app->make(Config::class)->get('database.default');
        $connection = is_string($event->options['database'] ?? null)
            ? $event->options['database']
            : (is_string($configured) ? $configured : 'unknown');

        if ($connection !== self::MIGRATION_CONNECTION) {
            throw new RuntimeException(sprintf(
                'DB-215: a migration runs under the migration account. Run it as '
                .'`php artisan migrate --database=%s` (or `composer migrate`); this run used %s.',
                self::MIGRATION_CONNECTION,
                $connection,
            ));
        }
    }

    /**
     * @throws SchemaConventionViolated
     */
    private function require(SchemaVerification $verification): void
    {
        if (! $verification->satisfied()) {
            throw new SchemaConventionViolated($verification->violations());
        }
    }

    /**
     * `DADR-09` — MySQL permits no wildcard in the table part of a `GRANT`, so a
     * table created by this migration carries no grant until they are recomputed.
     *
     * Skipped where no provisioning credential is configured: elsewhere a
     * database administrator applies the grants, and the application must not
     * hold a credential that could (`DB-122` ‡).
     */
    private function reapplyApplicationGrants(): void
    {
        if ($this->credential(self::PROVISIONING_CONNECTION, 'username') === null) {
            return;
        }

        $this->app->make(ProvisionsDatabaseAccounts::class)->reapplyApplicationGrants();
    }

    /**
     * @return array<value-of<DatabaseAccount>, array{username: string, password: string}>
     */
    private function configuredAccounts(): array
    {
        $connections = [
            DatabaseAccount::Application->value => self::APPLICATION_CONNECTION,
            DatabaseAccount::Migration->value => self::MIGRATION_CONNECTION,
            DatabaseAccount::Read->value => self::READ_CONNECTION,
        ];

        $accounts = [];

        foreach ($connections as $role => $connection) {
            $username = $this->credential($connection, 'username');
            $password = $this->credential($connection, 'password');

            if ($username === null || $password === null) {
                throw new RuntimeException(sprintf(
                    'DADR-09 requires three distinct accounts. The %s connection has no username or password; '
                    .'both are supplied by the environment (BE-015, SADR-14).',
                    $connection,
                ));
            }

            $accounts[$role] = ['username' => $username, 'password' => $password];
        }

        return $accounts;
    }

    private function credential(string $connection, string $key): ?string
    {
        $value = $this->app->make(Config::class)->get('database.connections.'.$connection.'.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function schemaName(): string
    {
        $schema = $this->app->make(Config::class)->get(
            'database.connections.'.self::APPLICATION_CONNECTION.'.database',
        );

        if (! is_string($schema) || $schema === '') {
            throw new RuntimeException('DB-001: the application connection names no schema.');
        }

        return $schema;
    }

    private function provisioningConnection(): Connection
    {
        $connection = $this->connection(self::PROVISIONING_CONNECTION);

        if (! $connection instanceof Connection) {
            throw new RuntimeException('The provisioning connection must be a database connection.');
        }

        return $connection;
    }

    private function connection(string $name): ConnectionInterface
    {
        return $this->app->make(ConnectionResolverInterface::class)->connection($name);
    }
}
