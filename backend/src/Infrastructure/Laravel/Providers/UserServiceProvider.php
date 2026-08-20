<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\SessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\User\RandomSessionTokens;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;

/**
 * The composition root for the `User` area.
 *
 * `BE-004` organises by domain area beneath each layer, and this is the first
 * area to have one — everything before it was cross-cutting.
 *
 * ## One policy key is declared, and two are not
 *
 * `PolicyServiceProvider` states the rule this follows: *"a key is declared on
 * the commit that gives something the code to read it — declaring one earlier
 * would create the accessor `BADR-12` says must not exist, for behaviour nothing
 * yet performs."*
 *
 * Three authentication values were decided on 2026-08-20. Only **one** has a
 * reader today:
 *
 * | Value | Decided | Declared here? |
 * |---|---|---|
 * | `SEC-039` ‡ session lifetime | 24 hours | **Yes** — declared in {@see PolicyServiceProvider}, read by {@see ResolveSession} on every request |
 * | `SEC-017` demonstration lifetime | 10 minutes | No — nothing issues or checks a demonstration yet |
 * | `SEC-049` concurrent session limit | 3 | No — it bites at establishment, and **what happens at the limit is not stated by any requirement** |
 *
 * ## The value is not set here either
 *
 * `BADR-12`: *"Every change is written by an operator action, validated before
 * application, and recorded evidentially."* The **figure** is recorded in
 * CMP-DOC-13 `SEC-039` ‡; applying it is an operator action through
 * {@see ChangePolicyValue}, which `BE-173`
 * evidences. `BE-171` keeps policy configuration out of deployment configuration,
 * so it is not an environment variable either.
 *
 * Until an operator applies it, `PolicyStore::read()` raises `PolicyNotSet` and
 * every session resolution fails. That is `SRS-REQ-158` working — an unconfigured
 * value is rejected — and it is preferable to a platform that picked its own
 * session lifetime.
 */
final class UserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SessionRepository::class,
            fn (Application $app): DatabaseSessionRepository => new DatabaseSessionRepository(
                $app->make(ConnectionResolverInterface::class)
                    ->connection(PersistenceServiceProvider::APPLICATION_CONNECTION),
            ),
        );

        // SEC-035 ‡ / SEC-036 ‡. Not SEC-028 ‡'s memory-hard construction —
        // CMP-DOC-13 §14.2 separates the two, and a high-entropy random token
        // needs no cost added to it.
        $this->app->singleton(
            HashesSessionTokens::class,
            static fn (): RandomSessionTokens => new RandomSessionTokens,
        );

        $this->app->bind(
            ResolveSession::class,
            static fn (Application $app): ResolveSession => new ResolveSession(
                $app->make(SessionRepository::class),
                $app->make(HashesSessionTokens::class),
                $app->make(PolicyStore::class),
                $app->make(Clock::class),
                PolicyServiceProvider::sessionLifetime(),
            ),
        );
    }
}
