<?php

declare(strict_types=1);

namespace Tests\Domain\Authorisation;

use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\AuthorisationRefusalCause;
use Cmp\Application\Shared\Authorisation\AuthorisationRefused;
use Cmp\Application\Shared\Authorisation\AuthorisationRule;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Capability;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Authorisation\Role;
use Cmp\Application\Shared\Authorisation\RoleKind;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Infrastructure\Authorisation\RegisteredActorRoles;
use Cmp\Infrastructure\Authorisation\RoleAssignmentNotAvailable;
use Tests\Domain\Authorisation\Doubles\RecordedRefusals;
use Tests\Domain\Authorisation\Doubles\TestRecord;
use Tests\Domain\DomainTestCase;

/**
 * `CC-032` — how an actor is resolved while `SEC-063` leaves the role set open.
 *
 * **Level 2** (`TC-029`): no database, no framework, no network. The register is
 * a declared list and the resolution reads it, so nothing here needs any of the
 * three — which is `TC-033`'s *earliest level that can verify it conclusively*.
 *
 * The decision this fixes in place: every actor resolves with **no roles**,
 * because `AuthorisationServiceProvider::roles()` is empty and `SEC-063` says
 * why. The tests assert both directions of that — what it permits, what it
 * refuses — and that it stops working the moment the condition that makes it
 * correct stops holding.
 */
final class RegisteredActorRolesTest extends DomainTestCase
{
    public function test_an_empty_register_is_what_the_platform_declares(): void
    {
        // The premise every other test here rests on: the register is empty,
        // and SEC-063 is why — "the mechanism is specified and the role set is
        // not." BAD-DEC-006 is open, and ADM-168 records the administrative
        // unit as blocked on it.
        //
        // That the PLATFORM's register is the empty one is asserted where the
        // framework may be named — AuthorisationRulesTest, level 1. TC-029
        // keeps a domain test away from a Laravel service provider, so this
        // level tests the mechanism against a register it declares itself, and
        // the two halves meet in the register's single declaration.
        self::assertSame([], $this->resolver()->actorFor(ActorReference::fromString('user-1'))->roles());
    }

    public function test_an_actor_resolves_holding_no_role(): void
    {
        // SEC-045 ‡: entitlement is evaluated against platform state. The state
        // is an empty register, so the answer is no roles — read, not assumed.
        $actor = $this->resolver()->actorFor(ActorReference::fromString('user-1'));

        self::assertSame([], $actor->roles());
        self::assertSame('user-1', $actor->reference()->toString());
    }

    public function test_the_resolved_actor_is_the_identity_the_session_named(): void
    {
        // SEC-044 ‡ binds a session to one actor, and SEC-045 ‡ forbids the
        // session carrying anything more. The reference passes through
        // unaltered; nothing is added to it here.
        foreach (['user-1', 'user-2'] as $reference) {
            self::assertTrue(
                $this->resolver()->actorFor(ActorReference::fromString($reference))
                    ->is(ActorReference::fromString($reference)),
            );
        }
    }

    public function test_a_party_rule_permits_a_role_less_actor(): void
    {
        // SEC-066 ‡ is satisfied by identity, which the session supplies. This
        // is why the two operations the platform states today are buildable
        // while SEC-063 is open — and the reason CC-032 chose this over waiting.
        $actor = $this->resolver()->actorFor(ActorReference::fromString('user-1'));

        $refusals = new RecordedRefusals;
        $authoriser = new Authoriser(
            AuthorisationPolicy::of(['test.party' => AuthorisationRule::requiringParty()]),
            $refusals,
        );

        $authoriser->authorise(Operation::named('test.party'), $actor, new TestRecord([ActorReference::fromString('user-1')]));

        self::assertSame([], $refusals->recorded());
    }

    public function test_a_capability_rule_refuses_a_role_less_actor(): void
    {
        // The negative direction, and the one that makes the incompleteness
        // safe. SEC-055 ‡ is deny-by-default: no role grants the capability, so
        // the operation is refused. A role-less actor can only ever be refused
        // MORE than a complete one — never permitted more.
        $actor = $this->resolver()->actorFor(ActorReference::fromString('user-1'));

        $refusals = new RecordedRefusals;
        $authoriser = new Authoriser(
            AuthorisationPolicy::of([
                'test.capability' => AuthorisationRule::requiringCapability(Capability::named('test.anything')),
            ]),
            $refusals,
        );

        try {
            $authoriser->authorise(Operation::named('test.capability'), $actor);
            self::fail('SEC-055 ‡: an actor holding no role holds no capability.');
        } catch (AuthorisationRefused) {
            // SEC-057 ‡: and the refusal was recorded before it was raised.
            self::assertSame(
                [AuthorisationRefusalCause::CapabilityNotHeld],
                array_column($refusals->recorded(), 'cause'),
            );
        }
    }

    public function test_an_administrative_rule_refuses_a_role_less_actor(): void
    {
        // SEC-061 / FRD-FR-254. ADM-168 says the administrative unit cannot
        // start without BAD-DEC-006; this is that holding at run time rather
        // than only in the tracker.
        $actor = $this->resolver()->actorFor(ActorReference::fromString('operator-1'));

        $refusals = new RecordedRefusals;
        $authoriser = new Authoriser(
            AuthorisationPolicy::of([
                'test.administrative' => AuthorisationRule::requiringAdministrativeCapability(
                    Capability::named('test.anything'),
                ),
            ]),
            $refusals,
        );

        $this->expectException(AuthorisationRefused::class);

        try {
            $authoriser->authorise(Operation::named('test.administrative'), $actor);
        } finally {
            self::assertSame(
                [AuthorisationRefusalCause::RoleKindNotHeld],
                array_column($refusals->recorded(), 'cause'),
            );
        }
    }

    public function test_resolution_stops_working_the_moment_a_role_is_declared(): void
    {
        // The half that matters most. "No roles" is correct because there is
        // nothing to assign, not because roles are ignored. The day BAD-DEC-006
        // decides the set, an actor must be resolved from a real assignment —
        // and this raises rather than silently reporting everyone as holding
        // nothing, which would refuse every administrative operation while
        // looking like a working implementation.
        $resolver = new RegisteredActorRoles([Role::user('rider', [])]);

        $this->expectException(RoleAssignmentNotAvailable::class);
        $this->expectExceptionMessageMatches('/read from platform state/');

        $resolver->actorFor(ActorReference::fromString('user-1'));
    }

    public function test_the_declared_role_kinds_are_both_reachable_by_the_check(): void
    {
        // TC-042: the rule is narrowed, never suppressed. Both kinds exist, so
        // the refusal above is a genuine "does not hold" rather than a check
        // that could never pass for anybody.
        self::assertSame(
            [RoleKind::User, RoleKind::Administrative],
            [Role::user('r', [])->kind(), Role::administrative('a', [])->kind()],
        );
    }

    private function resolver(): RegisteredActorRoles
    {
        // Empty, which is what the platform declares. Stated here rather than
        // read from the provider, because TC-029 forbids a domain test naming
        // a framework type and a service provider is one.
        return new RegisteredActorRoles([]);
    }
}
