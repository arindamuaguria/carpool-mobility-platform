<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Grants;

use Cmp\Application\Shared\Database\ProvisionsDatabaseAccounts;
use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * Applies the `DADR-09` grants using a privileged connection.
 *
 * Used by `db:provision-accounts` in development, and as the reference for what
 * a database administrator applies elsewhere. **The application never runs this
 * at request time and holds no credential that could.**
 *
 * `DB-122` ‡: no credential permitting alteration of the evidential domain is
 * available to the application **in any environment, including development** —
 * which is why the provisioning credential is separate, and why running this
 * narrows the application account rather than widening it.
 *
 * `CREATE USER`, `GRANT` and `REVOKE` are DDL: MySQL accepts no placeholder in
 * them, so no value can be bound. `DB-038` ‡ is honoured by the values not being
 * caller-supplied — they come from the environment — and by every identifier
 * being validated against a bare-identifier pattern before it reaches a
 * statement, with the password escaped by the driver rather than by hand.
 */
final class DatabaseAccountProvisioner implements ProvisionsDatabaseAccounts
{
    /**
     * @param  array<value-of<DatabaseAccount>, array{username: string, password: string}>  $accounts
     */
    public function __construct(
        private readonly Connection $privileged,
        private readonly GrantPlan $plan,
        private readonly array $accounts,
        private readonly string $host = '%',
    ) {}

    /**
     * @return list<string> the statements applied, with every password withheld
     */
    public function provisionAll(): array
    {
        $recorded = [];

        foreach ($this->accounts as $role => $account) {
            $this->createOrUpdateAccount($account['username'], $account['password']);
            $recorded[] = sprintf(
                "CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY <withheld>",
                $account['username'],
                $this->host,
            );

            $statements = [
                ...$this->plan->revokeAll($account['username'], $this->host),
                ...match (DatabaseAccount::from($role)) {
                    DatabaseAccount::Application => $this->plan->applicationGrants($account['username'], $this->tables(), $this->host),
                    DatabaseAccount::Migration => $this->plan->migrationGrants($account['username'], $this->host),
                    DatabaseAccount::Read => $this->plan->readGrants($account['username'], $this->host),
                },
            ];

            $this->run($statements);
            $recorded = [...$recorded, ...$statements];
        }

        $this->privileged->statement('FLUSH PRIVILEGES');

        return $recorded;
    }

    /**
     * Re-applies the application account's per-table grants.
     *
     * MySQL permits no wildcard in the table part of a `GRANT`, so a new table
     * carries no grant until this runs. It runs after every migration.
     *
     * @return list<string>
     */
    public function reapplyApplicationGrants(): array
    {
        $username = $this->accounts[DatabaseAccount::Application->value]['username'] ?? null;

        if (! is_string($username)) {
            return [];
        }

        $statements = [
            ...$this->plan->revokeAll($username, $this->host),
            ...$this->plan->applicationGrants($username, $this->tables(), $this->host),
        ];

        $this->run($statements);
        $this->privileged->statement('FLUSH PRIVILEGES');

        return $statements;
    }

    private function createOrUpdateAccount(string $username, string $password): void
    {
        $account = sprintf(
            '%s@%s',
            $this->quoteString($this->requireIdentifier($username)),
            $this->quoteString($this->requireIdentifier($this->host, allowWildcardHost: true)),
        );

        $secret = $this->privileged->getPdo()->quote($password);

        if ($secret === false) {
            throw new InvalidArgumentException('The driver could not quote the account password.');
        }

        $this->privileged->statement(sprintf('CREATE USER IF NOT EXISTS %s IDENTIFIED BY %s', $account, $secret));
        $this->privileged->statement(sprintf('ALTER USER %s IDENTIFIED BY %s', $account, $secret));
    }

    /**
     * @param  list<string>  $statements
     */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->privileged->statement($statement);
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        /** @var list<object{TABLE_NAME: string}> $rows */
        $rows = $this->privileged->select(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?',
            ['BASE TABLE'],
        );

        return array_map(static fn (object $row): string => $row->TABLE_NAME, $rows);
    }

    private function requireIdentifier(string $value, bool $allowWildcardHost = false): string
    {
        $pattern = $allowWildcardHost ? '/^[A-Za-z0-9_.%-]+$/' : '/^[A-Za-z0-9_]+$/';

        if (preg_match($pattern, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s is not a bare identifier.', $value));
        }

        return $value;
    }

    private function quoteString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
