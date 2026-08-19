<?php

declare(strict_types=1);

namespace Tests\Domain\Authorisation;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\AuthorisationRefusal;
use Cmp\Application\Shared\Authorisation\AuthorisationRefusalCause;
use Cmp\Application\Shared\Authorisation\AuthorisationRefused;
use Cmp\Application\Shared\Authorisation\AuthorisationRule;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Capability;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Authorisation\Role;
use Cmp\Application\Shared\Authorisation\RoleKind;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Domain\Shared\Refusal\RefusalKind;
use Tests\Domain\Authorisation\Doubles\RecordedRefusals;
use Tests\Domain\Authorisation\Doubles\TestRecord;
use Tests\Domain\DomainTestCase;

/**
 * CMP-IMP-033 — one authorisation evaluation, deny by default.
 *
 * Level 2 (`TC-029` ‡). `BADR-14` rejected authorisation inside the domain
 * partly because it *"makes domain tests carry identity"*; putting it in the
 * application layer is what lets it be tested without a database, a framework or
 * a request.
 *
 * The roles, capabilities and operations here are **test values**. The
 * platform's role set is `[TBD – Business Decision Required]` (`SEC-063`,
 * `BAD-DEC-006`) and its policy is empty; exercising the evaluation must never
 * require adding either.
 */
final class AuthoriserTest extends DomainTestCase
{
    public function test_an_operation_with_no_stated_rule_is_refused(): void
    {
        // SEC-055 ‡ / ARCH-134. SADR-06: "A new operation is refused until
        // someone states its rule, which fails safe."
        $refusals = new RecordedRefusals;
        $authoriser = new Authoriser(AuthorisationPolicy::of([]), $refusals);

        try {
            $authoriser->authorise($this->anOperation(), $this->anActor());
            self::fail('SEC-055 ‡: deny by default.');
        } catch (AuthorisationRefused $refused) {
            self::assertSame(AuthorisationRefusalCause::NoRuleStated, $refused->cause());
        }
    }

    public function test_a_stated_permissive_rule_permits(): void
    {
        // SEC-055 ‡ refuses silence, not a permissive rule that someone stated.
        $authoriser = $this->authoriserWith(AuthorisationRule::permittingAnyAuthenticatedActor());

        $authoriser->authorise($this->anOperation(), $this->anActor());

        self::assertTrue($authoriser->permits($this->anOperation(), $this->anActor()));
    }

    public function test_a_capability_the_actor_does_not_hold_is_refused(): void
    {
        // Negative test for FRD-FR-252 ‡: an action is permitted only where a
        // role held by the actor allows it.
        $authoriser = $this->authoriserWith(AuthorisationRule::requiringCapability($this->aCapability()));

        try {
            $authoriser->authorise($this->anOperation(), $this->anActor());
            self::fail('FRD-FR-252 ‡: a capability not held must be refused.');
        } catch (AuthorisationRefused $refused) {
            self::assertSame(AuthorisationRefusalCause::CapabilityNotHeld, $refused->cause());
        }
    }

    public function test_a_capability_held_through_a_role_permits(): void
    {
        // FRD-FR-251 ‡: the roles held are determined before the action is
        // permitted.
        $authoriser = $this->authoriserWith(AuthorisationRule::requiringCapability($this->aCapability()));
        $actor = Actor::holding($this->aReference('actor-1'), [Role::user('driver', [$this->aCapability()])]);

        self::assertTrue($authoriser->permits($this->anOperation(), $actor));
    }

    public function test_a_user_role_does_not_satisfy_an_administrative_requirement(): void
    {
        // FRD-FR-254 / SEC-061: the two kinds are restricted independently. A
        // user role granting the same capability must not open an administrative
        // operation.
        $authoriser = $this->authoriserWith(
            AuthorisationRule::requiringAdministrativeCapability($this->aCapability()),
        );
        $actor = Actor::holding($this->aReference('actor-1'), [Role::user('driver', [$this->aCapability()])]);

        try {
            $authoriser->authorise($this->anOperation(), $actor);
            self::fail('FRD-FR-254: an administrative operation needs an administrative role.');
        } catch (AuthorisationRefused $refused) {
            self::assertSame(AuthorisationRefusalCause::RoleKindNotHeld, $refused->cause());
        }

        $operator = Actor::holding($this->aReference('actor-1'), [
            Role::administrative('support', [$this->aCapability()]),
        ]);

        self::assertTrue($authoriser->permits($this->anOperation(), $operator));
        self::assertTrue($operator->holdsRoleOfKind(RoleKind::Administrative));
    }

    public function test_a_relationship_is_evaluated_against_the_record_the_platform_holds(): void
    {
        // BE-181 ‡ / SEC-056 ‡ / SEC-066 ‡. The target is a record, not a set of
        // identifiers a caller sent — a caller who could name the parties could
        // name themselves among them.
        $authoriser = $this->authoriserWith(AuthorisationRule::requiringParty());
        $record = new TestRecord([$this->aReference('actor-2'), $this->aReference('actor-3')]);

        try {
            $authoriser->authorise($this->anOperation(), $this->anActor(), $record);
            self::fail('SEC-066 ‡: a user reaches only records they are a party to.');
        } catch (AuthorisationRefused $refused) {
            self::assertSame(AuthorisationRefusalCause::NotAParty, $refused->cause());
        }

        $party = Actor::holding($this->aReference('actor-2'), []);
        self::assertTrue($authoriser->permits($this->anOperation(), $party, $record));
    }

    public function test_a_relationship_requirement_with_no_record_is_refused(): void
    {
        // An unverifiable requirement is not a met one, and SEC-055 ‡ fails safe.
        $authoriser = $this->authoriserWith(AuthorisationRule::requiringParty());

        try {
            $authoriser->authorise($this->anOperation(), $this->anActor(), null);
            self::fail('A relationship with nothing to evaluate it against must be refused.');
        } catch (AuthorisationRefused $refused) {
            self::assertSame(AuthorisationRefusalCause::NotAParty, $refused->cause());
        }
    }

    public function test_an_actor_cannot_alter_their_own_entitlement(): void
    {
        // Negative test for SEC-058 ‡. The rule below grants the capability and
        // the actor holds it — and it is still refused, because no stated rule
        // may opt out of an absolute one.
        $rule = AuthorisationRule::requiringCapability($this->aCapability())->alteringEntitlement();
        $authoriser = $this->authoriserWith($rule);

        $actor = Actor::holding($this->aReference('actor-1'), [
            Role::administrative('support', [$this->aCapability()]),
        ]);
        $ownEntitlement = new TestRecord([], $this->aReference('actor-1'));

        try {
            $authoriser->authorise($this->anOperation(), $actor, $ownEntitlement);
            self::fail('SEC-058 ‡: no operation permits a caller to alter their own entitlement.');
        } catch (AuthorisationRefused $refused) {
            self::assertSame(AuthorisationRefusalCause::WouldAlterOwnEntitlement, $refused->cause());
        }

        // Someone else's entitlement, with the capability held, is permitted.
        $anotherEntitlement = new TestRecord([], $this->aReference('actor-2'));
        self::assertTrue($authoriser->permits($this->anOperation(), $actor, $anotherEntitlement));
    }

    public function test_every_refusal_is_recorded(): void
    {
        // SEC-057 ‡ / BE-183 / API-088 ‡. A refusal nobody can see is a probe
        // nobody noticed.
        $refusals = new RecordedRefusals;
        $authoriser = new Authoriser(AuthorisationPolicy::of([]), $refusals);

        $authoriser->permits($this->anOperation(), $this->anActor());
        $authoriser->permits(Operation::named('test.another'), $this->anActor());

        self::assertCount(2, $refusals->recorded());
        self::assertSame('test.operation', $refusals->recorded()[0]['operation']);
        self::assertSame(AuthorisationRefusalCause::NoRuleStated, $refusals->recorded()[0]['cause']);
    }

    public function test_a_permitted_operation_records_nothing(): void
    {
        $refusals = new RecordedRefusals;
        $authoriser = new Authoriser(
            AuthorisationPolicy::of(['test.operation' => AuthorisationRule::permittingAnyAuthenticatedActor()]),
            $refusals,
        );

        $authoriser->authorise($this->anOperation(), $this->anActor());

        self::assertSame([], $refusals->recorded());
    }

    public function test_a_caller_is_told_the_same_thing_whatever_the_cause(): void
    {
        // SEC-069 ‡ / API-094 ‡: absence and non-entitlement are
        // indistinguishable, so that existence cannot be probed. There is one
        // caller-facing reason and adding a second would be the leak.
        self::assertCount(1, AuthorisationRefusal::cases());
        self::assertSame('access.not_available_to_you', AuthorisationRefusal::NotAvailableToYou->identifier());

        foreach (AuthorisationRefusalCause::cases() as $cause) {
            $refused = AuthorisationRefused::because($this->anOperation(), $cause);

            self::assertSame('access.not_available_to_you', $refused->reason()->identifier());
            self::assertSame('This is not available to you.', $refused->reason()->defaultText());
        }
    }

    public function test_a_refusal_is_a_business_refusal_and_not_a_fault(): void
    {
        // BADR-17 / BE-186 ‡; CMP-DOC-10 §8.6: "Caller not entitled → Business
        // refusal … Never a not-found, which would leak existence."
        $refused = AuthorisationRefused::because($this->anOperation(), AuthorisationRefusalCause::NotAParty);

        self::assertSame(RefusalKind::RuleDeclined, $refused->kind());
    }

    public function test_a_role_grants_capability_and_has_nowhere_to_express_an_exemption(): void
    {
        // SEC-010 ‡ / SEC-062 ‡ / SD-04: "An operator gains capability, never
        // exemption." A role holds capabilities and nothing else.
        $role = Role::administrative('support', [$this->aCapability()]);

        self::assertSame(['name', 'kind', 'capabilities', 'grants'], array_values(array_filter(
            get_class_methods($role),
            static fn (string $method): bool => ! str_starts_with($method, '__') && ! in_array($method, ['user', 'administrative'], true),
        )));
    }

    public function test_the_evaluation_returns_nothing_so_it_cannot_carry_an_exemption(): void
    {
        // SEC-062 ‡ structurally: authorise() permits or raises. A permitted
        // operation still runs through every domain invariant afterwards
        // (BE-044), because nothing the authoriser produces reaches the domain.
        $method = new \ReflectionMethod(Authoriser::class, 'authorise');

        self::assertSame('void', (string) $method->getReturnType());
    }

    public function test_the_policy_is_readable_as_a_catalogue(): void
    {
        // SADR-06 makes one path the whole defence of TB-1 and TB-2; a reviewer
        // has to be able to read what it permits.
        $policy = AuthorisationPolicy::of([
            'test.b' => AuthorisationRule::requiringParty(),
            'test.a' => AuthorisationRule::requiringCapability($this->aCapability()),
        ]);

        self::assertSame(
            ['test.a' => 'capability test.do_the_thing', 'test.b' => 'party to the record'],
            $policy->catalogue(),
        );
    }

    private function authoriserWith(AuthorisationRule $rule): Authoriser
    {
        return new Authoriser(
            AuthorisationPolicy::of(['test.operation' => $rule]),
            new RecordedRefusals,
        );
    }

    private function anOperation(): Operation
    {
        return Operation::named('test.operation');
    }

    private function anActor(): Actor
    {
        return Actor::holding($this->aReference('actor-1'), []);
    }

    private function aReference(string $value): ActorReference
    {
        return ActorReference::fromString($value);
    }

    private function aCapability(): Capability
    {
        return Capability::named('test.do_the_thing');
    }
}
