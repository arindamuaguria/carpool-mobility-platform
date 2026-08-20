<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Controller;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Application\User\AuthenticatedCaller;
use Cmp\Application\User\CurrentSessionCommand;
use Cmp\Application\User\RefreshCurrentSession;
use Cmp\Application\User\TerminateCurrentSession;
use Cmp\Interface\Rest\Envelope;
use Cmp\Interface\Rest\FailureResponse;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\Middleware\RequireSession;
use Cmp\Interface\Rest\ServedVersions;
use Cmp\Interface\Rest\SessionCarriage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

/**
 * `DELETE /sessions/current` and `POST /sessions/current/refresh`.
 *
 * The REST halves of `CMP-IMP-057` and `CMP-IMP-056`. `BE-005` makes this an
 * adapter and nothing more: it reads what the middleware resolved, builds a
 * command (`BE-042` — no transport type crosses into it), invokes exactly one
 * application service (`API-002` ‡) and serialises the result.
 *
 * It holds no rule. Whether the caller may perform either operation is
 * `AuthorisationServiceProvider`'s rule, evaluated by
 * `ApplicationService::execute()` — `SEC-054` ‡ and `API-097` ‡ forbid deciding
 * it here, and `API-050` ‡ forbids deciding what a representation discloses here
 * too.
 *
 * ## The refreshed token leaves in a header
 *
 * `SEC-038` ‡ keeps a token out of every **response body**, so `data` carries the
 * fact of the refresh and the token goes in {@see SessionCarriage::ISSUE_HEADER}.
 * The reasoning, and why `API-101` ‡ does not forbid issuing it at all, is in
 * {@see SessionCarriage}.
 */
final class CurrentSessionController
{
    public function __construct(
        private readonly TerminateCurrentSession $terminate,
        private readonly RefreshCurrentSession $refresh,
        private readonly EvaluationTime $evaluatedAt,
    ) {}

    /**
     * `FRD-FR-019` — terminate the session on the user's request.
     */
    public function destroy(Request $request): JsonResponse
    {
        $result = $this->terminate->execute(...$this->invocation($request));

        if ($result->isFailure()) {
            return FailureResponse::from($result->failure(), $this->evaluatedAt->stamp());
        }

        // FRD-FR-020 clears the device's cached business data when a session
        // ends; the client does that, and MOB-144 clears the session material
        // with it. The body says what happened and carries nothing else.
        return Envelope::of(
            ['terminated' => true],
            Envelope::meta(ServedVersions::CURRENT, $this->evaluatedAt->stamp()),
        );
    }

    /**
     * `SEC-043` / `NFR-055` — a new token, within the bound.
     */
    public function refresh(Request $request): JsonResponse
    {
        $result = $this->refresh->execute(...$this->invocation($request));

        if ($result->isFailure()) {
            return FailureResponse::from($result->failure(), $this->evaluatedAt->stamp());
        }

        $token = $result->value();

        if (! is_string($token)) {
            throw new LogicException('SEC-043: a refresh issues a new token.');
        }

        $response = Envelope::of(
            // API-064: a client can tell a fresh outcome from a replayed one, and
            // SEC-038 ‡ keeps the token itself out of here.
            ['refreshed' => true],
            Envelope::meta(ServedVersions::CURRENT, $this->evaluatedAt->stamp()),
        );

        return $response->withHeaders([SessionCarriage::ISSUE_HEADER => $token]);
    }

    /**
     * The command and the actor, from what the middleware resolved.
     *
     * Both come out of one {@see AuthenticatedCaller}, which belongs to the
     * Application layer. `BE-002` keeps this adapter from naming the domain
     * session inside it, and the layer graph rejected an earlier version of this
     * method that did.
     *
     * @return array{0: CurrentSessionCommand, 1: Actor}
     */
    private function invocation(Request $request): array
    {
        $caller = $request->attributes->get(RequireSession::CALLER);
        $key = $request->headers->get(RequireIdempotencyKey::HEADER);

        if (! $caller instanceof AuthenticatedCaller || ! is_string($key)) {
            // Both are guaranteed by middleware the route registers. Reaching
            // here means the route was registered outside the group, which is a
            // fault rather than anything a caller did.
            throw new LogicException(
                'A session operation runs behind RequireSession and RequireIdempotencyKey.'
            );
        }

        return [
            CurrentSessionCommand::from($caller, IdempotencyKey::fromString($key)),
            $caller->actor(),
        ];
    }
}
