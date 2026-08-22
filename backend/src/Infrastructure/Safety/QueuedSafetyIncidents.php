<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Safety;

use Cmp\Application\Safety\RoutesSafetyIncidents;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Domain\Safety\IncidentReference;
use Cmp\Infrastructure\Job\RouteSafetyIncident;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * `FRD-FR-189` ‡ — the operator queue is the `safety` job family.
 *
 * `BRD-REQ-112` requires every incident *"routed to an operator queue for
 * response"*, and `ADR-06`/`BADR-05` decided durable queued work in MySQL with
 * `safety` as its own highest-priority family. Placing the incident there is
 * dispatching {@see RouteSafetyIncident}.
 *
 * ## The idempotency key is derived, not invented
 *
 * `BE-135` ‡ requires a job safe to execute more than once, and the registry
 * needs a key to recognise a repeat by. It is derived from the **incident
 * reference**, so every dispatch for one incident — the first, the retry after a
 * failure, the retry after a process died — carries the same key and produces
 * one routing. A random key per dispatch would make `FRD-FR-190` ‡'s retry a
 * way to route the same incident twice.
 *
 * `DB-023` ‡ already makes the reference unguessable, so a derived key discloses
 * nothing a caller did not already hold.
 */
final class QueuedSafetyIncidents implements RoutesSafetyIncidents
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function route(IncidentReference $reference, ActorReference $actor): void
    {
        $this->dispatcher->dispatch(new RouteSafetyIncident(
            $actor->toString(),
            self::keyFor($reference),
            $reference->toString(),
        ));
    }

    /**
     * One key per incident, for every attempt to route it.
     */
    public static function keyFor(IncidentReference $reference): string
    {
        return 'safety-route-'.$reference->toString();
    }
}
