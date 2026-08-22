<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Controller;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Configuration\ConfigurationVersion;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Idempotency\RegisteredOutcome;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Application\Shared\Result;
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
        private readonly ConfigurationVersion $configurationVersion,
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

        $outcome = $this->outcomeOf($result);

        // FRD-FR-020 clears the device's cached business data when a session
        // ends; the client does that, and MOB-144 clears the session material
        // with it. The body says what happened and carries nothing else.
        return $this->envelope($outcome->representation() ?? ['terminated' => true], $outcome->replayed());
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

        $outcome = $this->outcomeOf($result);
        $representation = $outcome->representation() ?? [];
        $token = $representation['token'] ?? null;

        // SEC-038 ‡: the token never appears in the body. It is lifted out here
        // and put in a header, and what is left is what the client reads.
        unset($representation['token']);

        $response = $this->envelope($representation, $outcome->replayed());

        if (! is_string($token)) {
            // A **replay** carries no token, because SEC-038 ‡ kept it out of the
            // registry — see OperationOutcome. The client is told the refresh
            // happened and that this is a replay (API-064), which is enough for it
            // to try again under a new key if it never received the first one.
            return $response;
        }

        return $response->withHeaders([SessionCarriage::ISSUE_HEADER => $token]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function envelope(array $data, bool $replayed): JsonResponse
    {
        return Envelope::of($data, Envelope::meta(
            ServedVersions::CURRENT,
            $this->evaluatedAt->stamp(),
            $this->configurationVersion->current(),
            $replayed,
        ));
    }

    private function outcomeOf(Result $result): RegisteredOutcome
    {
        $outcome = $result->value();

        if (! $outcome instanceof RegisteredOutcome) {
            // API-062 ‡ is applied in ApplicationService for every state-changing
            // command, so a session operation cannot answer with anything else.
            throw new LogicException('API-062 ‡: a state-changing operation answers with a registered outcome.');
        }

        return $outcome;
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
