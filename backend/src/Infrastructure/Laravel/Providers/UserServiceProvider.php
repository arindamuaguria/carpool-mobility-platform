<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Application\User\EstablishSession;
use Cmp\Application\User\HashesAuthenticationMaterial;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\RefreshCurrentSession;
use Cmp\Application\User\ResolveSession;
use Cmp\Application\User\TerminateCurrentSession;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Cmp\Infrastructure\User\Argon2idAuthenticationMaterial;
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
 * ## Which policy keys have a reader, and which do not
 *
 * `PolicyServiceProvider` states the rule this follows: *"a key is declared on
 * the commit that gives something the code to read it — declaring one earlier
 * would create the accessor `BADR-12` says must not exist, for behaviour nothing
 * yet performs."*
 *
 * | Value | Decided | Has a reader? |
 * |---|---|---|
 * | `SEC-039` ‡ session lifetime | 24 hours | **Yes** — {@see ResolveSession}, on every request |
 * | `SEC-030` Argon2id memory, iterations, lanes | Deployment-time (`SEC-031`) | **Yes** — {@see Argon2idAuthenticationMaterial}, on every hash |
 * | `SEC-017` demonstration lifetime | 10 minutes | No — nothing issues or checks a demonstration yet |
 * | `SEC-049` concurrent session limit | 3 | **Yes** — {@see EstablishSession}, once per establishment |
 *
 * ## None of the values is set here either
 *
 * `BADR-12`: *"Every change is written by an operator action, validated before
 * application, and recorded evidentially."* The **figures** are recorded in
 * CMP-DOC-13; applying one is an operator action through
 * {@see ChangePolicyValue}, which `BE-173` evidences. `BE-171` keeps policy
 * configuration out of deployment configuration, so none is an environment
 * variable either.
 *
 * Until an operator applies them, `PolicyStore::read()` raises `PolicyNotSet`:
 * every session resolution fails, and nothing hashes. That is `SRS-REQ-158`
 * working — an unconfigured value is rejected — and it is preferable to a
 * platform that picked its own session lifetime or its own hash cost.
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

        // SEC-028 ‡ / SEC-244 ‡. The other of CMP-DOC-13 §14.2's two
        // constructions, and the one that carries cost — a demonstration is
        // short and a person reads it off a screen, so its input space is small
        // and cost is the entire defence.
        $this->app->singleton(
            HashesAuthenticationMaterial::class,
            static fn (Application $app): Argon2idAuthenticationMaterial => new Argon2idAuthenticationMaterial(
                $app->make(PolicyStore::class),
                ...PolicyServiceProvider::authenticationHashCost(),
            ),
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

        $this->app->singleton(
            UserRepository::class,
            fn (Application $app): DatabaseUserRepository => new DatabaseUserRepository(
                $app->make(ConnectionResolverInterface::class)
                    ->connection(PersistenceServiceProvider::APPLICATION_CONNECTION),
                $app->make(Clock::class),
            ),
        );

        // CMP-IMP-053. SEC-051 ‡, FRD-FR-018 and SEC-243 ‡ are stated once here,
        // for every caller that ever establishes a session — which is why the
        // authentication that precedes it is not this service's.
        $this->app->bind(
            EstablishSession::class,
            static fn (Application $app): EstablishSession => new EstablishSession(
                $app->make(Authoriser::class),
                $app->make(TransactionBoundary::class),
                $app->make(UserRepository::class),
                $app->make(SessionRepository::class),
                $app->make(HashesSessionTokens::class),
                $app->make(PolicyStore::class),
                $app->make(RecordsEvidence::class),
                $app->make(Clock::class),
                PolicyServiceProvider::sessionLifetime(),
                PolicyServiceProvider::concurrentSessionLimit(),
            ),
        );

        // CMP-IMP-057 / CMP-IMP-056. Both are ApplicationServices, so both go
        // through the single authorisation evaluation SADR-06 requires, and
        // AuthorisationServiceProvider::sessionRules() states the rule each needs.
        $this->app->bind(
            TerminateCurrentSession::class,
            static fn (Application $app): TerminateCurrentSession => new TerminateCurrentSession(
                $app->make(Authoriser::class),
                $app->make(TransactionBoundary::class),
                $app->make(SessionRepository::class),
                $app->make(RecordsEvidence::class),
                $app->make(Clock::class),
            ),
        );

        $this->app->bind(
            RefreshCurrentSession::class,
            static fn (Application $app): RefreshCurrentSession => new RefreshCurrentSession(
                $app->make(Authoriser::class),
                $app->make(TransactionBoundary::class),
                $app->make(SessionRepository::class),
                $app->make(HashesSessionTokens::class),
                $app->make(RecordsEvidence::class),
                $app->make(Clock::class),
            ),
        );
    }
}
