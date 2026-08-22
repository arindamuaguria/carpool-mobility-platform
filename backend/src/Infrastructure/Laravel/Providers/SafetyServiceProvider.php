<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Safety\GeneratesIncidentReferences;
use Cmp\Application\Safety\MarkIncidentRouted;
use Cmp\Application\Safety\RaiseSafetyIncident;
use Cmp\Application\Safety\ReadOwnIncident;
use Cmp\Application\Safety\RetrySafetyRouting;
use Cmp\Application\Safety\RoutesSafetyIncidents;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Safety\SafetyIncidentRepository;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Infrastructure\Persistence\Safety\DatabaseSafetyIncidentRepository;
use Cmp\Infrastructure\Safety\QueuedSafetyIncidents;
use Cmp\Infrastructure\Safety\RandomIncidentReferences;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;

/**
 * The composition root for `UC-051`.
 *
 * A provider of its own rather than a section of `UserServiceProvider`, and
 * `BE-192` ‡ is the reason: *"the safety surface shall depend on the minimum set
 * of components required to record and dispatch."* What the safety capability
 * needs is visible here in one place, and `BE-193` ‡'s prohibition — no payment,
 * search, matching, rating or projection component — is checkable by reading a
 * single file rather than by tracing a graph.
 *
 * `SafetyDependencyRulesTest` checks it mechanically as well, because
 * `BADR-18`/`TC-042` put mechanical enforcement ahead of a reviewer noticing.
 */
final class SafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SafetyIncidentRepository::class,
            fn (Application $app): DatabaseSafetyIncidentRepository => new DatabaseSafetyIncidentRepository(
                $app->make(ConnectionResolverInterface::class)
                    ->connection(PersistenceServiceProvider::APPLICATION_CONNECTION),
                $app->make(Clock::class),
            ),
        );

        // DB-023 ‡. The identifiers that matter most.
        $this->app->singleton(
            GeneratesIncidentReferences::class,
            static fn (): RandomIncidentReferences => new RandomIncidentReferences,
        );

        // FRD-FR-189 ‡ / BE-132 ‡: the operator queue is the `safety` family,
        // and nothing else can delay it.
        $this->app->singleton(
            RoutesSafetyIncidents::class,
            static fn (Application $app): QueuedSafetyIncidents => new QueuedSafetyIncidents(
                $app->make(Dispatcher::class),
            ),
        );

        $this->app->bind(
            RaiseSafetyIncident::class,
            static fn (Application $app): RaiseSafetyIncident => new RaiseSafetyIncident(
                $app->make(Authoriser::class),
                $app->make(TransactionBoundary::class),
                $app->make(SafetyIncidentRepository::class),
                $app->make(GeneratesIncidentReferences::class),
                $app->make(RoutesSafetyIncidents::class),
                $app->make(RecordsEvidence::class),
                $app->make(Clock::class),
            ),
        );

        $this->app->bind(
            MarkIncidentRouted::class,
            static fn (Application $app): MarkIncidentRouted => new MarkIncidentRouted(
                $app->make(Authoriser::class),
                $app->make(TransactionBoundary::class),
                $app->make(SafetyIncidentRepository::class),
                $app->make(RecordsEvidence::class),
                $app->make(Clock::class),
            ),
        );

        $this->app->bind(
            ReadOwnIncident::class,
            static fn (Application $app): ReadOwnIncident => new ReadOwnIncident(
                $app->make(Authoriser::class),
                $app->make(SafetyIncidentRepository::class),
            ),
        );

        // FRD-FR-190 ‡. Invoked by `safety:route-pending`, never scheduled —
        // BE-148 makes scheduled-work frequency policy configuration and none is
        // set, so a schedule here would be a figure invented where nobody would
        // look for it.
        $this->app->bind(
            RetrySafetyRouting::class,
            static fn (Application $app): RetrySafetyRouting => new RetrySafetyRouting(
                $app->make(SafetyIncidentRepository::class),
                $app->make(RoutesSafetyIncidents::class),
            ),
        );
    }
}
