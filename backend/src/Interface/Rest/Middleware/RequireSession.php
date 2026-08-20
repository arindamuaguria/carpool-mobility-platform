<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Middleware;

use Closure;
use Cmp\Application\Shared\Authorisation\ResolvesActorRoles;
use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Application\User\AuthenticatedCaller;
use Cmp\Application\User\ResolveSession;
use Cmp\Application\User\SessionRefusalCause;
use Cmp\Application\User\SessionRefused;
use Cmp\Interface\Rest\FailureResponse;
use Cmp\Interface\Rest\SessionCarriage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `API-095` ‡ — every operation outside CMP-DOC-10 §9.1 requires an
 * authenticated session.
 *
 * The token arrives in a request header ({@see SessionCarriage}), is resolved
 * against the store by {@see ResolveSession} — `SEC-042`'s hash-and-lookup on
 * every request — and the resulting identity becomes an `Actor` through
 * {@see ResolvesActorRoles}, which is `SEC-045` ‡'s *"evaluated against platform
 * state"*.
 *
 * ## It authenticates and does not authorise
 *
 * `SEC-054` ‡ and `API-097` ‡ are explicit that authorisation *"shall not be
 * implemented in transport middleware alone"*, and this implements none of it:
 * it establishes **who** is calling and stops. Whether they may perform the
 * operation is decided by `ApplicationService::execute()` against the rule
 * `AuthorisationServiceProvider` states, which is the single evaluation `SADR-06`
 * requires.
 *
 * That division is why a missing session is refused here and a forbidden
 * operation is refused there. Both reach the caller as a business refusal, and
 * `SEC-069` ‡ and `SEC-048` ‡ between them make the two indistinguishable.
 *
 * ## Absent, malformed, unknown, terminated and expired are one answer
 *
 * `SEC-048` ‡ makes terminated, expired and unknown indistinguishable, and
 * `API-107` gives non-entitlement the same shape. A request carrying **no**
 * header is answered the same way rather than more helpfully: telling an
 * unauthenticated caller that their header was missing rather than invalid
 * distinguishes nothing worth distinguishing, and one answer is one fewer thing
 * to get wrong.
 */
final class RequireSession
{
    /**
     * Where the resolved session and actor are put for the controller.
     *
     * `BE-042` keeps a command free of transport types, so the controller reads
     * these and builds one; nothing downstream sees the request.
     */
    public const CALLER = 'cmp.caller';

    public function __construct(
        private readonly ResolveSession $sessions,
        private readonly ResolvesActorRoles $roles,
        private readonly EvaluationTime $evaluatedAt,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = SessionCarriage::tokenIn($request->headers->get(SessionCarriage::REQUEST_HEADER));

        if ($token === null) {
            // No token names no session, which is SessionRefusalCause::Unknown
            // exactly. SEC-048 ‡ answers it the same way as the other two.
            return $this->refuse(SessionRefusalCause::Unknown);
        }

        try {
            $session = $this->sessions->forToken($token);
        } catch (SessionRefused $refused) {
            return $this->refuse($refused->cause());
        }

        $request->attributes->set(self::CALLER, new AuthenticatedCaller(
            $session,
            // SEC-045 ‡: the session supplies an identity; what it may do is read
            // from platform state, never carried in the token.
            $this->roles->actorFor(ActorReference::fromString($session->user()->toString())),
        ));

        return $next($request);
    }

    /**
     * One refusal, whatever the reason (`SEC-048` ‡).
     *
     * `API-087` maps a state conflict to `409`, which {@see FailureResponse}
     * derives from the reason itself — so the status follows the refusal rather
     * than being chosen here.
     */
    private function refuse(SessionRefusalCause $cause): Response
    {
        return FailureResponse::from(
            BusinessRefused::from(SessionRefused::because($cause)),
            $this->evaluatedAt->stamp(),
        );
    }
}
