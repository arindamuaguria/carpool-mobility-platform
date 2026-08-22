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
use Cmp\Domain\Safety\IncidentContext;
use Cmp\Domain\Safety\SafetyIncident;
use Cmp\Domain\Safety\SafetyIncidentRepository;
use Cmp\Domain\Shared\Time\Clock;
use LogicException;
use Throwable;

/**
 * `FRD-FR-185` ‡ — every safety signal becomes a record.
 *
 * `UC-051`'s minimal guarantee is the whole specification of this class: *"No
 * safety signal is ever lost, and none is closed without a record."*
 *
 * ## The order, and why it is not the usual one
 *
 * Persist, **commit**, then route. Every other service on the platform does its
 * work in one transaction; this one deliberately does not, and `FRD-FR-190` ‡ is
 * why: *"retain and retry an incident that **cannot immediately reach** the
 * operator queue."* An incident that could not be routed is a state the
 * requirement names and expects to exist, so routing cannot be inside the
 * transaction that records the signal — a rollback would take the signal with
 * it, and `FRD-FR-188` ‡ forbids discarding one.
 *
 * `API-169` ‡ agrees from the other side: *"Acceptance shall be acknowledged
 * only after the signal is persisted."* So the caller's acknowledgement rests on
 * the commit, not on the queue.
 *
 * ## A routing failure is not a refusal
 *
 * If {@see RoutesSafetyIncidents::route()} throws, the incident stands, unrouted,
 * and {@see RetrySafetyRouting} finds it again by query — not by anything this
 * process remembered, because a process that died here remembers nothing. The
 * failure is recorded as an operational condition (`BE-138` ‡: *"a failed safety
 * job shall raise an operational condition immediately"*) and the caller is told
 * the signal was accepted, **because it was**.
 *
 * `BE-186` ‡ is not strained by that: nothing is being represented as a business
 * refusal. The operation succeeded — the record exists — and a downstream
 * delivery is outstanding.
 *
 * ## What is captured
 *
 * `FRD-FR-186` ‡ names five context elements and the platform can obtain one of
 * them: who raised it. The other four stand at `unavailable`, each for a reason
 * recorded in the migration, and `FRD-FR-187` ‡ is explicit that this is a
 * recordable incident rather than a deficient one — *"marking the missing
 * context as unavailable."*
 *
 * `BE-194` ‡ closes the loop: *"Recording a safety incident shall succeed where
 * any non-essential dependency is unavailable."* There is nothing here to be
 * unavailable — no provider, no projection, no configuration read — which is
 * `BE-192` ‡ and `API-166` ‡ satisfied by construction rather than by care.
 */
final class RaiseSafetyIncident extends ApplicationService
{
    public const ACTION = 'safety.incident_raised';

    public const ROUTING_DEFERRED_ACTION = 'safety.incident_routing_deferred';

    public function __construct(
        Authoriser $authoriser,
        private readonly TransactionBoundary $transaction,
        private readonly SafetyIncidentRepository $incidents,
        private readonly GeneratesIncidentReferences $references,
        private readonly RoutesSafetyIncidents $routing,
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
    ) {
        parent::__construct($authoriser);
    }

    public function operation(): Operation
    {
        return Operation::named('safety.incidents.raise');
    }

    protected function target(Command $command): ?AuthorisationTarget
    {
        return $command instanceof RaiseIncidentCommand ? $command : null;
    }

    protected function handle(Command $command, Actor $actor): Result
    {
        if (! $command instanceof RaiseIncidentCommand) {
            throw new LogicException(self::class.' records a signal raised by the calling user.');
        }

        $incident = SafetyIncident::raise(
            $this->references->next(),
            $command->raiser(),
            $this->clock->now(),
            // FRD-FR-187 ‡. Everything unknown, honestly — see IncidentContext.
            IncidentContext::unavailable(),
        );

        $this->transaction->transactional(function () use ($incident, $actor): void {
            $this->incidents->save($incident);

            // FRD-FR-248 ‡ / UC-051 step 4: an action whose evidential record
            // cannot be written is not complete, and BE-106 ‡ puts the record in
            // the transaction that carries the effect. BE-201 ‡: the record
            // names the incident, never its circumstances.
            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor->reference()->toString()),
                self::ACTION,
                $incident->reference()->toString(),
                EvidentialOutcome::Succeeded,
                $this->clock->now(),
            ));
        });

        // Committed. FRD-FR-189 ‡ from here on, and nothing below can lose the
        // signal above.
        try {
            $this->routing->route($incident->reference(), $actor->reference());
        } catch (Throwable) {
            // FRD-FR-190 ‡: retained, and retried by query. BE-138 ‡ wants this
            // visible immediately rather than discovered later, and the
            // evidential record is what makes "it was raised at 14:02 and
            // reached the queue at 14:39" answerable afterwards.
            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor->reference()->toString()),
                self::ROUTING_DEFERRED_ACTION,
                $incident->reference()->toString(),
                EvidentialOutcome::Failed,
                $this->clock->now(),
            ));
        }

        return Result::success(IncidentView::of($incident));
    }
}
