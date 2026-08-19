<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Database;

/**
 * Creates the three `DADR-09` accounts and applies their grants.
 *
 * The application account holds `SELECT`, `INSERT`, `UPDATE`, `DELETE` on the
 * operational, projection and machinery domains, and `SELECT`, `INSERT` only on
 * the evidential and ledger domains — no `UPDATE`, no `DELETE`, no `DDL`
 * (`DB-118` ‡, `DB-119` ‡). The migration account holds `DDL` and is used only
 * by migrations (`DB-215`). The read account holds `SELECT`.
 *
 * `DB-122` ‡: no credential permitting alteration of the evidential domain is
 * available to the application in any environment. The implementation therefore
 * uses a separate provisioning credential, and the platform holds none at
 * runtime.
 */
interface ProvisionsDatabaseAccounts
{
    /**
     * @return list<string> the statements applied, with every password withheld
     */
    public function provisionAll(): array;

    /**
     * Re-applies the application account's per-table grants.
     *
     * MySQL permits no wildcard in the table part of a `GRANT`, so a table
     * created by a migration carries no grant until this runs.
     *
     * @return list<string>
     */
    public function reapplyApplicationGrants(): array;
}
