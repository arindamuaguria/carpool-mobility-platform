<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Safety\IncidentReference;
use Cmp\Domain\Safety\SafetyIncidentRepository;
use Cmp\Domain\Shared\Time\Clock;
use LogicException;

/**
 * `FRD-FR-189` ‡ — the incident has reached the safety operator queue.
 *
 * The worker end of routing. A job on the `safety` family runs this, and running
 * it *is* the incident being in the queue: `BRD-REQ-112` requires it *"routed to
 * an operator queue for response"*, and `BE-132` ‡ makes that family the one
 * nothing else can delay.
 *
 * ## What this does not do
 *
 * It does not respond, notify, dispatch or escalate. `BRD-REQ-113` — the
 * protocol itself — is **Blocked** on `BAD-DEC-011`; `BRD-REQ-114` — whether,
 * when and how emergency contacts are informed — is Blocked on the same
 * decision; `GAP-004` leaves emergency dispatch open and CMP-DOC-10 §12.4 states
 * that the interface says nothing about it. `ADM-168` records that the operator
 * unit which would work the queue cannot start without `BAD-DEC-006`.
 *
 * So the queue has a producer and no consumer beyond this mark, and that is the
 * documented state of the platform rather than an unfinished edge. CMP-DOC-02 is
 * explicit about which half is buildable: *"Build the incident infrastructure in
 * R1; do not expose the SOS control to users until `BAD-DEC-011` is resolved and
 * `BAD-DEP-012` (a staffed response capability) exists."*
 *
 * ## Idempotent, because a queue redelivers
 *
 * `BE-135` ‡. {@see SafetyIncident::routed()} keeps the first
 * instant, so a redelivery cannot move the time at which the signal reached the
 * queue — which is the figure `FRD-FR-190` ‡'s retention is measured against.
 */
final class MarkIncidentRouted extends ApplicationService
{
    public const ACTION = 'safety.incident_routed';

    public function __construct(
        Authoriser $authoriser,
        private readonly TransactionBoundary $transaction,
        private readonly SafetyIncidentRepository $incidents,
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
    ) {
        parent::__construct($authoriser);
    }

    public function operation(): Operation
    {
        return Operation::named('safety.incidents.route');
    }

    protected function target(Command $command): ?AuthorisationTarget
    {
        if (! $command instanceof RouteIncidentCommand) {
            return null;
        }

        $incident = $this->incidents->forReference(IncidentReference::fromString($command->reference()));

        return $incident === null ? null : new RaisedIncident($incident);
    }

    protected function handle(Command $command, Actor $actor): Result
    {
        if (! $command instanceof RouteIncidentCommand) {
            throw new LogicException(self::class.' marks an incident as having reached the queue.');
        }

        $incident = $this->incidents->forReference(IncidentReference::fromString($command->reference()));

        if ($incident === null) {
            throw new LogicException(
                'The authorisation evaluation loaded this incident, so it exists.'
            );
        }

        if ($incident->isRouted()) {
            // BE-135 ‡: a second delivery is not a second routing, and it is not
            // an error either. Nothing is written and nothing is evidenced —
            // BE-107 ‡ wants a record of what happened, and this time nothing
            // did.
            return Result::success(IncidentView::of($incident));
        }

        $this->transaction->transactional(function () use ($incident, $actor): void {
            $incident->routed($this->clock->now());
            $this->incidents->save($incident);

            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor->reference()->toString()),
                self::ACTION,
                $incident->reference()->toString(),
                EvidentialOutcome::Succeeded,
                $this->clock->now(),
            ));
        });

        // Nothing reads this. DB-143 records a job\u0027s success value as the
        // representation it replays, and that must be an array or null \u2014 an
        // IncidentView here would be a domain-shaped object in the idempotency
        // registry, which is neither what the registry stores nor what any
        // caller of this service wants. The job is the only caller.
        return Result::succeeded();
    }
}
