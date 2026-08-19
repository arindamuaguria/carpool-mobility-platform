<?php

declare(strict_types=1);

namespace Cmp\Interface\Console;

use Cmp\Application\Shared\Database\ProvisionsDatabaseAccounts;
use Illuminate\Console\Command;

/**
 * Creates the three `DADR-09` accounts and applies their grants.
 *
 * A development convenience and the reference for what a database administrator
 * applies elsewhere. It uses the **provisioning** credential, which is not the
 * application's: `DB-122` ‡ requires that no credential permitting alteration of
 * the evidential domain be available to the application in any environment,
 * development included.
 *
 * Running it **narrows** the application account. A database created by a
 * container image typically grants its user everything on the schema; this
 * revokes that and re-grants per domain, so that `DB-118` ‡ and `DB-119` ‡ hold
 * locally exactly as they must everywhere else.
 *
 * An adapter (`BE-005`): it holds no rule and reaches the component that does
 * through the application contract alone.
 */
final class ProvisionDatabaseAccountsCommand extends Command
{
    protected $signature = 'db:provision-accounts';

    protected $description = 'Create the three DADR-09 database accounts and apply their grants.';

    public function handle(ProvisionsDatabaseAccounts $accounts): int
    {
        foreach ($accounts->provisionAll() as $statement) {
            $this->line('  '.$statement);
        }

        $this->components->info('DADR-09 — the three accounts exist and hold exactly their documented grants.');

        return self::SUCCESS;
    }
}
