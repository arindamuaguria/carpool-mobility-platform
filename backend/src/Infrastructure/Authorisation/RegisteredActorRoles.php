<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Authorisation;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationRule;
use Cmp\Application\Shared\Authorisation\ResolvesActorRoles;
use Cmp\Application\Shared\Authorisation\Role;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Infrastructure\Laravel\Providers\AuthorisationServiceProvider;

/**
 * Resolves an actor from the platform's declared role register.
 *
 * `SEC-045` ‡: *"A session shall carry no authorisation claim; entitlement shall
 * be evaluated against platform state on every request."* This is that
 * evaluation — the session supplies an identity and nothing else, and what the
 * identity may do is read here.
 *
 * ## An actor holds no roles, because there are none to hold
 *
 * `SEC-063`: *"Role definitions and their capabilities are `[TBD – Business
 * Decision Required]`; **the mechanism is specified and the role set is not**."*
 * `AuthorisationServiceProvider::roles()` is empty in consequence, and has been
 * since `CMP-IMP-033`.
 *
 * So every actor resolves with **no roles**. That is not a decision taken here:
 * it is what an empty register means. `CC-032` records the reasoning and the
 * alternative that was rejected — waiting for `BAD-DEC-006` would have left every
 * authenticated operation unbuildable, and `ADM-168` already blocks the
 * administrative unit on that decision without blocking this one.
 *
 * ## Why that is safe, and in which direction
 *
 * `SEC-055` ‡ is deny-by-default. A rule requiring a capability
 * ({@see AuthorisationRule::requiringCapability()})
 * or a role kind is **refused** for such an actor, because no role grants either.
 * The incompleteness can therefore only ever **under-permit**, never
 * over-permit — a role-less actor is refused more than a complete one would be,
 * and refusing too much is visible while permitting too much is not.
 *
 * The two rules the platform states today need no role at all: `SEC-066` ‡'s
 * party rule is satisfied by identity, which the session supplies.
 *
 * ## What is deliberately absent
 *
 * **No role assignment is read**, because there is nowhere to read one from:
 * assigning a role to a user needs a role set to assign from, and `SEC-063`
 * leaves that undecided. `CMP-IMP-051` created `op_users`,
 * `op_user_credentials` and `op_sessions` and no role table, and `CLAUDE.md`
 * forbids adding an undocumented one to remove a blocker.
 *
 * When `BAD-DEC-006` decides the role set, this class gains the assignment read
 * and {@see AuthorisationServiceProvider::roles()}
 * gains its entries. `RegisteredActorRolesTest` asserts both halves of the
 * present position, so that day fails a test rather than passing unnoticed.
 */
final class RegisteredActorRoles implements ResolvesActorRoles
{
    /**
     * @param  list<Role>  $declaredRoles  the platform's role register
     */
    public function __construct(private readonly array $declaredRoles) {}

    public function actorFor(ActorReference $reference): Actor
    {
        // With no role declared, there is nothing any user could have been
        // assigned. The register is consulted rather than assumed, so that the
        // day it is not empty this stops being a constant.
        if ($this->declaredRoles === []) {
            return Actor::holding($reference, []);
        }

        // SEC-045 ‡ would read the assignment from platform state here. There is
        // no state to read: a role set exists but no table assigns one, so
        // resolving would mean inventing an assignment.
        throw new RoleAssignmentNotAvailable(
            'SEC-045 ‡: a role set is now declared, so an actor\'s roles must be read from platform state '
            .'rather than assumed empty. No assignment exists to read — CMP-IMP-051 created no role table, '
            .'because SEC-063 was open when it ran. Add the assignment before adding the role.'
        );
    }
}
