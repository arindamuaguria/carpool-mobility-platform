<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Domain\Safety\SafetyIncidentRepository;
use Throwable;

/**
 * `FRD-FR-190` ‡ — *"retain and retry an incident that cannot immediately reach
 * the operator queue."*
 *
 * The retry. Retention is the row: {@see RaiseSafetyIncident} commits the
 * incident before it attempts to route, so a routing failure — or a process that
 * died between the two — leaves a record with `routed_at` null. This finds every
 * one of those **by query** and dispatches it again.
 *
 * A query rather than a retry queue is the point. A process that died after
 * committing remembers nothing it was about to do; a table does. `UC-081`
 * covers the same ground for work generally, and `UC-051` E2 states this case in
 * terms: *"Queue unreachable. The incident is recorded and retried. It is never
 * dropped."*
 *
 * ## Not an `ApplicationService`, and why
 *
 * It performs no operation on any user's behalf and has no actor whose
 * entitlement could be evaluated — it re-dispatches work the platform already
 * accepted, each incident under the identity that raised it. `SEC-055` ‡ refuses
 * an operation with no stated rule, and inventing a rule for *"the platform
 * retrying itself"* would put a role-shaped hole in a policy `SEC-063` has
 * deliberately left empty. The operation it eventually performs —
 * {@see MarkIncidentRouted} — is authorised in the ordinary way when the job
 * runs.
 *
 * ## What invokes it
 *
 * `safety:route-pending`, a console command an operator or a cron entry runs.
 * **It is not scheduled.** `BE-148` makes scheduled-work frequency policy
 * configuration and no frequency is set — `CMP-IMP-029` declared the scheduling
 * point and nothing is declared in it — so a schedule here would be a figure
 * invented in the one place nobody would look for it. The retry exists and is
 * invocable; when it runs is a deployment decision CMP-DOC-19 records.
 */
final class RetrySafetyRouting
{
    /**
     * How many to re-dispatch in one pass.
     *
     * Bounded because an unbounded read of this table is the one query that must
     * not fall over when it matters most — `BD-04`. A pass that finds the bound
     * full leaves the rest for the next pass, which is why the query is
     * oldest-first: the earliest signal is the one an operator most needs.
     */
    public const BATCH = 100;

    public function __construct(
        private readonly SafetyIncidentRepository $incidents,
        private readonly RoutesSafetyIncidents $routing,
    ) {}

    /**
     * @return array{attempted: int, dispatched: int, more: bool}
     */
    public function execute(): array
    {
        $pending = $this->incidents->unrouted(self::BATCH);
        $dispatched = 0;

        foreach ($pending->all() as $incident) {
            try {
                $this->routing->route(
                    $incident->reference(),
                    ActorReference::fromString($incident->raisedBy()->toString()),
                );

                $dispatched++;
            } catch (Throwable) {
                // Still unrouted, still retained, and the next pass finds it
                // again. FRD-FR-188 ‡: nothing here discards a signal, and one
                // incident the queue will not take must not stop the rest.
            }
        }

        return [
            'attempted' => $pending->count(),
            'dispatched' => $dispatched,
            // BD-04: a full batch is not the same answer as an empty backlog, and
            // an operator reading the second where the truth is the first would
            // stop running the retry.
            'more' => $pending->mayBeMore(),
        ];
    }
}
