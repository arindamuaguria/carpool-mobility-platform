<?php

declare(strict_types=1);

namespace Cmp\Interface\Safety\Controller;

use Cmp\Application\Safety\IncidentView;
use Cmp\Application\Safety\RaiseIncidentCommand;
use Cmp\Application\Safety\RaiseSafetyIncident;
use Cmp\Application\Safety\ReadOwnIncident;
use Cmp\Application\Safety\ReadOwnIncidentCommand;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Application\Shared\Result;
use Cmp\Application\User\AuthenticatedCaller;
use Cmp\Interface\Rest\Envelope;
use Cmp\Interface\Rest\FailureResponse;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\Middleware\RequireSession;
use Cmp\Interface\Safety\SafetySurface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * `/safety/v1/incidents` — CMP-DOC-10 §12.1.
 *
 * `BE-005` makes this an adapter, and `BE-192` ‡ makes it a **thin** one: *"the
 * safety surface shall depend on the minimum set of components required to
 * record and dispatch."* Every dependency is a way to fail, so what is not here
 * is as much the specification as what is.
 *
 * ## No configuration version in the envelope
 *
 * The general surface stamps `configuration_version` on every response. This one
 * does not. CMP-DOC-10 §12.3 omits configuration fetch from the safety surface
 * — *"would make recording depend on configuration"* — and `API-194` ‡ requires
 * that a failure to fetch configuration *"shall not prevent the client from
 * raising a safety incident"*. Reading a version to stamp would be exactly that
 * dependency, however tolerantly it failed.
 *
 * ## Nothing is said about a response
 *
 * `NFR-137` forbids implying a protection the platform does not provide.
 * `GAP-004` leaves emergency dispatch open, and CMP-DOC-10 §12.4 states the
 * position: *"the interface states nothing about dispatch."* `BRD-REQ-113` — the
 * response protocol — and `BRD-REQ-114` — informing emergency contacts — are
 * both Blocked on `BAD-DEC-011`. So an acknowledgement here says the signal was
 * recorded and reached a queue, and nothing whatever about anybody acting on it.
 * `SafetyIncidentEndpointTest` checks the served bytes against that.
 *
 * ## This is not the SOS control
 *
 * `FRD-FR-195` ‡ and `BAD-RISK-005` withhold the **control**, and CMP-DOC-12
 * §17 records that no SOS affordance is designed. That prohibition binds the
 * client. `FRD-FR-185` ‡ — *"record **every** safety signal it receives"* —
 * binds the platform unconditionally, and CMP-DOC-02 is explicit that the two
 * are separable: *"Build the incident infrastructure in R1; do not expose the
 * SOS control to users until `BAD-DEC-011` is resolved."*
 */
final class IncidentController
{
    public function __construct(
        private readonly RaiseSafetyIncident $raise,
        private readonly ReadOwnIncident $read,
        private readonly EvaluationTime $evaluatedAt,
    ) {}

    /**
     * `POST /incidents` — `FRD-FR-185` ‡.
     *
     * There is no request schema, and that is `API-164` ‡ rather than an
     * omission: the shape is *"the minimum that permits recording, so that a
     * signal is never lost to a validation failure on a non-essential field."*
     * The operation accepts no field at all, so there is nothing to validate and
     * nothing a caller can get wrong.
     *
     * A body naming one of `API-037` ‡'s authoritative values is still refused —
     * `RefuseAssertedAuthority` runs on this group as it does on the general one,
     * because `FRD-FR-238` ‡ is about **every** request.
     */
    public function store(Request $request): JsonResponse
    {
        [$caller, $key] = $this->carriage($request);

        return $this->respond($this->raise->execute(
            RaiseIncidentCommand::from($caller, $key),
            $caller->actor(),
        ));
    }

    /**
     * `GET /incidents/{id}` — the raiser reads their own record.
     */
    public function show(Request $request, string $version, string $id): JsonResponse
    {
        $caller = $this->caller($request);

        return $this->respond($this->read->execute(
            ReadOwnIncidentCommand::from($caller, $id),
            $caller->actor(),
        ));
    }

    private function respond(Result $result): JsonResponse
    {
        if ($result->isFailure()) {
            return FailureResponse::from($result->failure(), $this->evaluatedAt->stamp());
        }

        $incident = $result->value();

        if (! $incident instanceof IncidentView) {
            throw new LogicException('UC-051: both operations answer with the incident.');
        }

        return Envelope::of(
            ['incident' => $incident->toArray()],
            // No configuration version — see the class note.
            Envelope::meta(SafetySurface::CURRENT, $this->evaluatedAt->stamp()),
        );
    }

    /**
     * @return array{0: AuthenticatedCaller, 1: IdempotencyKey}
     */
    private function carriage(Request $request): array
    {
        $key = $request->headers->get(RequireIdempotencyKey::HEADER);

        if (! is_string($key)) {
            throw new LogicException('API-066 ‡: idempotency applies to the safety surface exactly as to the general one.');
        }

        return [$this->caller($request), IdempotencyKey::fromString($key)];
    }

    private function caller(Request $request): AuthenticatedCaller
    {
        $caller = $request->attributes->get(RequireSession::CALLER);

        if (! $caller instanceof AuthenticatedCaller) {
            throw new LogicException('A safety operation runs behind RequireSession.');
        }

        return $caller;
    }
}
