<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\AuthorisationRule;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\RecordsAuthorisationRefusals;
use Cmp\Application\Shared\Authorisation\ResolvesActorRoles;
use Cmp\Application\Shared\Authorisation\Role;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Infrastructure\Authorisation\EvidentialAuthorisationRefusals;
use Cmp\Infrastructure\Authorisation\RegisteredActorRoles;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;

/**
 * The one place an authorisation rule and a role are declared.
 *
 * `SADR-06` makes one evaluation the whole defence of `TB-1` and `TB-2`. A
 * reviewer therefore has to be able to read, in one place, what that evaluation
 * permits and who holds what.
 */
final class AuthorisationServiceProvider extends ServiceProvider
{
    /**
     * The platform's authorisation policy.
     *
     * **Seven rules, each added with the operation it governs** —
     * see {@see sessionRules()}. That is the standing rule: a rule is added on
     * the commit that adds the operation it governs, never ahead of it, because
     * a rule for an operation that does not exist permits nothing and reviews as
     * though something had been decided.
     *
     * Everything else is still refused, and `SEC-055` ‡ is why that is the
     * correct behaviour rather than a shortfall: an operation with no stated rule
     * **is refused**. `SADR-06` accepts the consequence in terms — *"Every
     * operation needs an explicit rule before it works at all."*
     */
    public static function policy(): AuthorisationPolicy
    {
        return AuthorisationPolicy::of([...self::sessionRules(), ...self::emergencyContactRules()]);
    }

    /**
     * The platform's roles, and the capabilities each grants.
     *
     * **Empty, and blocked.**
     *
     * `SEC-063`: *"Role definitions and their capabilities are
     * `[TBD – Business Decision Required]`; the mechanism is specified and the
     * role set is not."* `BAD-DEC-006` is open. `ADM-168` records that the
     * administrative unit cannot start without it, and the readiness analysis
     * lists `BAD-DEC-006` as blocking deployment for that reason.
     *
     * Inventing a role here would be inventing the business decision — and
     * `SEC-062` ‡ would then rest on a role set nobody agreed.
     *
     * @return list<Role>
     */
    public static function roles(): array
    {
        return [];
    }

    /**
     * Establishing a session, and the two operations a caller may perform on one
     * they already hold.
     *
     * All three take {@see AuthorisationRule::requiringParty()} — no capability,
     * no role kind, nothing that touches the role set `SEC-063` leaves undecided.
     * `SEC-066` ‡ is the whole rule: *"a user shall access only records to which
     * they are a party"*, and `SEC-044` ‡ binds a session to exactly one actor,
     * so the party is unambiguous and there is no list to get wrong.
     *
     * **None alters entitlement**, so none is marked
     * {@see AuthorisationRule::alteringEntitlement()}. `SEC-058` ‡ and
     * `API-105` ‡ guard a caller changing their own permissions; establishing,
     * terminating or refreshing a session changes what the caller holds, not
     * what they may do — `SEC-045` ‡ keeps every claim out of a session, so
     * there is nothing in one to alter.
     *
     * @return array<string, AuthorisationRule>
     */
    public static function sessionRules(): array
    {
        return [
            // CMP-IMP-053. The party is the identity the session will be bound
            // to (`SEC-044` ‡), which is what stops a caller establishing one
            // for somebody else — a rule rather than a check inside the service.
            'sessions.establish' => AuthorisationRule::requiringParty(),
            'sessions.current.terminate' => AuthorisationRule::requiringParty(),
            'sessions.current.refresh' => AuthorisationRule::requiringParty(),
        ];
    }

    /**
     * `UC-048` — the four operations on a user’s own emergency contacts.
     *
     * All four take {@see AuthorisationRule::requiringParty()}, and the party
     * is the user the session is bound to. `SEC-066` ‡ is the whole rule:
     * *"a user shall access only records to which they are a party"* — a
     * nomination is a record of the **user**, not of the person nominated,
     * who has no account and whom `UC-OQ-006` records the platform may never
     * even tell.
     *
     * **None alters entitlement.** `SEC-058` ‡ guards a caller changing their
     * own permissions; nominating somebody as an emergency contact grants that
     * person nothing, because there is nothing to grant — `FRD-GAP-020` blocks
     * every part of what a contact would be informed of, and no capability,
     * role or access follows from being named.
     *
     * The read has a rule of its own rather than sharing the write’s.
     * `SEC-055` ‡ refuses an operation with no stated rule, and one rule
     * covering four operations would be one place to widen four at once.
     *
     * @return array<string, AuthorisationRule>
     */
    public static function emergencyContactRules(): array
    {
        return [
            'profile.emergency_contacts.read' => AuthorisationRule::requiringParty(),
            'profile.emergency_contacts.nominate' => AuthorisationRule::requiringParty(),
            'profile.emergency_contacts.amend' => AuthorisationRule::requiringParty(),
            'profile.emergency_contacts.remove' => AuthorisationRule::requiringParty(),
        ];
    }

    public function register(): void
    {
        $this->app->singleton(AuthorisationPolicy::class, static fn (): AuthorisationPolicy => self::policy());

        // SEC-057 ‡: every refused authorisation is recorded, and the record is
        // the evidential one — BE-202 forbids operational logging standing in
        // for it. The writer arrived with CMP-IMP-439, so it no longer has to.
        $this->app->singleton(
            RecordsAuthorisationRefusals::class,
            static fn (Application $app): EvidentialAuthorisationRefusals => new EvidentialAuthorisationRefusals(
                $app->make(RecordsEvidence::class),
                $app->make(Clock::class),
                $app->make(LogManager::class),
            ),
        );

        $this->app->singleton(
            Authoriser::class,
            static fn (Application $app): Authoriser => new Authoriser(
                $app->make(AuthorisationPolicy::class),
                $app->make(RecordsAuthorisationRefusals::class),
            ),
        );

        // CC-032: bound on 2026-08-20. SEC-045 ‡ evaluates entitlement against
        // platform state, and the state is the role register — which SEC-063
        // leaves empty, so every actor resolves with no roles. SEC-055 ‡ makes
        // that safe in the refusing direction only, and RegisteredActorRoles
        // raises rather than guessing the moment a role is declared without an
        // assignment to read.
        $this->app->singleton(
            ResolvesActorRoles::class,
            static fn (): RegisteredActorRoles => new RegisteredActorRoles(self::roles()),
        );

        // This binding was withheld until CC-032, on the ground that a stub
        // returning an actor with no roles "would look like an implementation and
        // behave like one". CC-032 settled that it is not a stub: an actor holds
        // the roles the register assigns, the register is empty, and that is the
        // recorded consequence of SEC-063 rather than a decision. What made the
        // difference is that RegisteredActorRoles reads the register instead of
        // returning a constant, and raises the moment a role exists without an
        // assignment — so it cannot outlive the condition that makes it correct.
    }
}
