<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Domain\Safety\IncidentReference;

/**
 * `FRD-FR-189` ‡ — how an incident reaches the safety operator queue.
 *
 * *"The system shall place every safety incident in the safety operator
 * queue."* `BRD-REQ-112` is the business statement. `BE-132` ‡ is what makes the
 * queue the right one: safety work has its own family and never queues behind
 * anything else, because `SRS-REQ-060` requires it prioritised above all other
 * work and `BADR-05` rejected a single default queue in terms — *"safety work
 * would queue behind projection rebuilds under load."*
 *
 * ## A port, so that failing to route is a recordable event
 *
 * The implementation dispatches a job. This exists as a contract rather than a
 * direct dispatch so that `FRD-FR-190` ‡ has something to fail: *"retain and
 * retry an incident that **cannot immediately reach** the operator queue."* A
 * platform that could not express the failure could not retain anything.
 *
 * ## The reference, and nothing else
 *
 * `BE-201` ‡ governs what may be put in a job payload — *"no payment
 * credential, **no precise location**, no contact detail"* — because the payload
 * is readable by anyone who can read the job store. A safety incident's context
 * is the most sensitive the platform holds, so what travels is the identifier,
 * and the worker reads the record.
 */
interface RoutesSafetyIncidents
{
    /**
     * Place it in the queue.
     *
     * May throw. The caller has already persisted the incident (`API-169` ‡) and
     * treats a failure here as `FRD-FR-190` ‡'s retain-and-retry case, never as
     * a reason to lose the signal (`FRD-FR-188` ‡).
     */
    public function route(IncidentReference $reference, ActorReference $actor): void;
}
