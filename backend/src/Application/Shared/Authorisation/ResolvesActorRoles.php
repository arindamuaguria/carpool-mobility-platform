<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

use Cmp\Application\Shared\Idempotency\ActorReference;

/**
 * Builds an {@see Actor} from the platform's own record of what an identity
 * holds.
 *
 * `SEC-045` ‡: *"A session shall carry no authorisation claim; entitlement shall
 * be evaluated against platform state on every request."* This exists so there
 * is exactly one way to obtain an `Actor`, and it goes to state.
 *
 * **No implementation exists.** `op_users` and the role assignment it would read
 * arrive with FEAT-004 and FEAT-007, and `SEC-063` records the role definitions
 * themselves as `[TBD – Business Decision Required]` pending `BAD-DEC-006`.
 * Until then every resolved actor holds no roles, which — with deny-by-default
 * (`SEC-055` ‡) — means every operation requiring a capability is refused. That
 * is the correct behaviour for a platform whose role set nobody has decided.
 */
interface ResolvesActorRoles
{
    public function actorFor(ActorReference $reference): Actor;
}
