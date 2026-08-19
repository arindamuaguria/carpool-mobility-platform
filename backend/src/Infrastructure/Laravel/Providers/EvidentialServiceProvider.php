<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Evidence\RaisesForReconciliation;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Evidence\VerifiesEvidentialChain;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialChainVerifier;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Evidential\KeyedChainHash;
use Cmp\Infrastructure\Evidential\LoggingReconciliationRaises;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * The composition root for the evidential log.
 *
 * Three accounts, three jobs, and the separation is the point (`DADR-09`):
 *
 * - the **writer** runs under the application account, which holds `SELECT` and
 *   `INSERT` on `ev_` and neither `UPDATE` nor `DELETE` (`DB-118` ‡);
 * - the **verifier** runs under the read account, which holds `SELECT` alone —
 *   a verifier that could alter what it verifies would be worth very little;
 * - nothing runs under the migration account except a migration.
 *
 * `SEC-106` ‡: the chain key is held **outside the database** and is not
 * readable through a database connection. It is injected at deploy time
 * (`SADR-14`, `OPS-098`) and named — never valued — in the environment
 * inventory.
 *
 * `SEC-172` ‡ requires the key to be **escrowed**, because losing it makes the
 * evidential guarantee unverifiable — not the data unreadable, but the guarantee
 * void. Escrow is an operational obligation this file cannot discharge, and it is
 * recorded here so it is not mistaken for something the code handles.
 */
final class EvidentialServiceProvider extends ServiceProvider
{
    /**
     * `TECH-DEC-005` makes `.env.example` the environment inventory; this is the
     * name it lists, and it never lists a value.
     */
    public const CHAIN_KEY_VARIABLE = 'EVIDENTIAL_CHAIN_KEY';

    public function register(): void
    {
        $this->app->singleton(KeyedChainHash::class, function (Application $app): KeyedChainHash {
            $key = $app->make(Config::class)->get('evidential.chain_key');

            if (! is_string($key) || $key === '') {
                throw new RuntimeException(sprintf(
                    'SEC-106 ‡: the evidential chain key is missing. Set %s; it is injected at deploy time '
                    .'and never held in the database (SADR-14, OPS-098). SEC-172 ‡ requires it escrowed — '
                    .'losing it makes the chain unverifiable.',
                    self::CHAIN_KEY_VARIABLE,
                ));
            }

            return new KeyedChainHash($key);
        });

        // BE-105 ‡: one writer, on the application account.
        $this->app->singleton(
            RecordsEvidence::class,
            fn (Application $app): DatabaseEvidentialWriter => new DatabaseEvidentialWriter(
                $app->make(ConnectionResolverInterface::class)
                    ->connection(PersistenceServiceProvider::APPLICATION_CONNECTION),
                $app->make(KeyedChainHash::class),
            ),
        );

        // SEC-112 ‡: verification reports and never repairs. The read account
        // makes that a property of the credential.
        $this->app->singleton(
            VerifiesEvidentialChain::class,
            fn (Application $app): DatabaseEvidentialChainVerifier => new DatabaseEvidentialChainVerifier(
                $app->make(ConnectionResolverInterface::class)
                    ->connection(PersistenceServiceProvider::READ_CONNECTION),
                $app->make(KeyedChainHash::class),
            ),
        );

        $this->app->singleton(
            RaisesForReconciliation::class,
            static fn (Application $app): LoggingReconciliationRaises => new LoggingReconciliationRaises(
                $app->make(LogManager::class),
            ),
        );
    }
}
