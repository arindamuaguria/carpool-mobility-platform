<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Grants;

use Cmp\Infrastructure\Persistence\Schema\SchemaConventions;
use InvalidArgumentException;

/**
 * Computes the grants each account must hold, from the tables that exist.
 *
 * `DADR-09`. The application account's grant differs **per storage domain**, and
 * MySQL permits no wildcard in the table part of a `GRANT`, so the plan is
 * per-table and is recomputed whenever the schema changes. That is the price of
 * `DB-118` ‡ — the application holding neither `UPDATE` nor `DELETE` on `ev_` or
 * `led_` — and it is the whole point: the claim becomes verifiable by inspecting
 * a grant rather than by reading every line of code.
 *
 * Pure. It emits statements; it does not execute them.
 */
final class GrantPlan
{
    /** Domains the application may write freely (`DADR-09`). */
    private const APPLICATION_READ_WRITE_DOMAINS = ['op_', 'proj_', 'mch_'];

    /**
     * Domains the application may only append to.
     *
     * `DB-118` ‡ evidential, `DB-094` ledger: `SELECT` and `INSERT`, never
     * `UPDATE`, never `DELETE`.
     *
     * `cfg_` is here for the same reason by a different route. **`DADR-09` does
     * not state a grant for the configuration domain** — it names operational,
     * projection and machinery as read-write and evidential and ledger as
     * append-only, and is silent on the sixth. The narrowest reading consistent
     * with the rest of the chain is applied and reported: `BE-169` requires the
     * application to read policy on nearly every decision, and `DB-152` requires
     * every change to produce **a new version rather than an update in place**.
     * Withholding `UPDATE` and `DELETE` makes that a property of the credential
     * rather than of the code — the same technique `DADR-09` uses for evidence.
     *
     * This is a documentation gap, not a decision taken here. If the Project
     * Owner rules that the configuration domain is read-write, this line changes
     * and `cfg_policy_values` may carry a current-version pointer again.
     */
    private const APPLICATION_APPEND_ONLY_DOMAINS = ['ev_', 'led_', 'cfg_'];

    /**
     * `DB-119` ‡: the application holds no `DDL` privilege on any domain, so it
     * cannot remove a constraint or a trigger that constrains it.
     */
    private const MIGRATION_PRIVILEGES = [
        'SELECT', 'INSERT', 'UPDATE', 'DELETE',
        'CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES',
        'CREATE VIEW', 'SHOW VIEW', 'TRIGGER', 'LOCK TABLES',
    ];

    /**
     * @param  string  $schema  The one schema of `DB-001`.
     */
    public function __construct(private readonly string $schema)
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $schema) !== 1) {
            // DB-038 ‡ forbids concatenating a caller-supplied value into a
            // statement. A GRANT cannot bind its identifiers, so the identifier
            // is constrained instead of trusted.
            throw new InvalidArgumentException('A schema name must be a bare identifier.');
        }
    }

    /**
     * Statements that revoke everything from an account, so that the plan is the
     * whole truth rather than an addition to whatever was there before.
     *
     * @return list<string>
     */
    public function revokeAll(string $user, string $host = '%'): array
    {
        return [sprintf(
            'REVOKE ALL PRIVILEGES, GRANT OPTION FROM %s',
            $this->account($user, $host),
        )];
    }

    /**
     * The application account: per-table, and different per domain.
     *
     * A table in no recognised domain gets **no grant at all**. `DB-002` and
     * `DB-014` make domain membership readable from the name; a table that
     * declares no domain is a schema defect, and defaulting it to read-write
     * would quietly grant more than the design allows.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function applicationGrants(string $user, array $tables, string $host = '%'): array
    {
        $account = $this->account($user, $host);
        $statements = [];

        foreach ($tables as $table) {
            $domain = SchemaConventions::domainOf($table);

            $privileges = match (true) {
                in_array($domain, self::APPLICATION_READ_WRITE_DOMAINS, true) => 'SELECT, INSERT, UPDATE, DELETE',
                in_array($domain, self::APPLICATION_APPEND_ONLY_DOMAINS, true) => 'SELECT, INSERT',
                default => null,
            };

            if ($privileges === null) {
                continue;
            }

            $statements[] = sprintf(
                'GRANT %s ON %s.%s TO %s',
                $privileges,
                $this->quote($this->schema),
                $this->quote($this->requireIdentifier($table)),
                $account,
            );
        }

        return $statements;
    }

    /**
     * `DB-215`: the migration account is the only account holding `DDL`.
     *
     * @return list<string>
     */
    public function migrationGrants(string $user, string $host = '%'): array
    {
        return [sprintf(
            'GRANT %s ON %s.* TO %s',
            implode(', ', self::MIGRATION_PRIVILEGES),
            $this->quote($this->schema),
            $this->account($user, $host),
        )];
    }

    /**
     * `DADR-09`: the read account holds `SELECT` and is used for reporting. It
     * cannot alter evidence because it cannot alter anything.
     *
     * @return list<string>
     */
    public function readGrants(string $user, string $host = '%'): array
    {
        return [sprintf(
            'GRANT SELECT ON %s.* TO %s',
            $this->quote($this->schema),
            $this->account($user, $host),
        )];
    }

    private function account(string $user, string $host): string
    {
        return sprintf(
            '%s@%s',
            $this->quoteString($this->requireIdentifier($user)),
            $this->quoteString($host),
        );
    }

    private function requireIdentifier(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('%s is not a bare identifier.', $value));
        }

        return $value;
    }

    private function quote(string $identifier): string
    {
        return '`'.$identifier.'`';
    }

    private function quoteString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
