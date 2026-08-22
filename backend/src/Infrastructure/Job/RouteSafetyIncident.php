<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Job;

use Cmp\Application\Safety\MarkIncidentRouted;
use Cmp\Application\Safety\RouteIncidentCommand;
use Cmp\Application\Shared\Authorisation\ResolvesActorRoles;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\Work\JobFamily;

/**
 * `FRD-FR-189` ‡ — the platform's first job, and it is a safety job.
 *
 * `BE-131`/`BE-132` ‡ put it on the `safety` family, which `BADR-05` gives the
 * highest priority precisely so that it never queues behind a projection
 * rebuild. `SRS-REQ-060` requires safety work prioritised above all other, and
 * the base class binds the queue in its constructor, so there is no path by
 * which this lands anywhere else.
 *
 * ## What the payload carries
 *
 * The incident reference. Nothing else. `BE-201` ‡ governs a job payload
 * expressly — *"no payment credential, **no precise location**, no contact
 * detail"* — because the payload is readable by anyone who can read the job
 * store, and an incident's circumstances are the most sensitive thing the
 * platform holds. The worker reads the record.
 *
 * ## Safe to run twice
 *
 * `BE-135` ‡. A queue redelivers after a worker dies mid-run, after a timeout
 * and after a deploy. Two effects make this harmless: the run goes through the
 * idempotency registry (`API-062` ‡), and the aggregate keeps the **first**
 * routing instant, so a redelivery cannot move the time the signal reached the
 * queue.
 *
 * That sentence names no Domain type on purpose. `BE-011` keeps a job reaching
 * the domain **through an application service**, and `StructuralRulesTest` rule 8
 * recognises a job that has stopped doing so by the Domain import it carries —
 * which a `{@see}` reference becomes the moment Pint expands it.
 *
 * ## `BE-134`: it contains no rule
 *
 * It calls one application service and holds nothing. Whether the caller may
 * mark an incident routed is the authorisation policy's, evaluated inside
 * `execute()` like every other caller's (`SADR-06` — *"a queue worker … bypassing
 * the HTTP stack would bypass authorisation entirely"*).
 */
final class RouteSafetyIncident extends PlatformJob
{
    public function __construct(
        string $actorReference,
        string $idempotencyKeyValue,
        private readonly string $incidentReference,
    ) {
        parent::__construct($actorReference, $idempotencyKeyValue);
    }

    public function family(): JobFamily
    {
        return JobFamily::Safety;
    }

    public function operationName(): string
    {
        return 'safety.incidents.route';
    }

    /**
     * @return array<string, scalar|null>
     */
    protected function payload(): array
    {
        return ['incident' => $this->incidentReference];
    }

    protected function perform(): Result
    {
        // BE-140: the identity is carried on the job, because a worker has no
        // ambient session to read one from. SEC-063 leaves the role set empty,
        // so the actor resolves with none and the party rule is what decides.
        return app(MarkIncidentRouted::class)->execute(
            new RouteIncidentCommand($this->incidentReference),
            app(ResolvesActorRoles::class)->actorFor($this->actorReference()),
        );
    }
}
