<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Domain\User\Session;
use Cmp\Interface\Rest\Middleware\RequireSession;

/**
 * Who is calling, and the session that says so.
 *
 * The two things {@see RequireSession} establishes
 * before an operation runs: the session `SEC-042` looked up, and the actor
 * `SEC-045` ‡ resolved from platform state.
 *
 * ## Why this exists rather than passing the session itself
 *
 * `BE-002` and `BE-003` keep the `Interface` layer from naming a `Domain` type,
 * and {@see Session} is one. An adapter that held a `Session` would be an adapter
 * reaching two layers inward — and the layer graph caught exactly that when this
 * surface was first written.
 *
 * So the adapter holds this, which is the Application layer's, and the session
 * inside it is only ever read by the Application layer. The rule is not a
 * formality here: it is what stops a controller from asking a session whether it
 * has expired, which is `SEC-039` ‡'s question and belongs where the policy is
 * read.
 */
final class AuthenticatedCaller
{
    public function __construct(
        private readonly Session $session,
        private readonly Actor $actor,
    ) {}

    public function session(): Session
    {
        return $this->session;
    }

    /**
     * `SEC-045` ‡: resolved against platform state on this request, and carrying
     * no claim the session supplied.
     */
    public function actor(): Actor
    {
        return $this->actor;
    }
}
