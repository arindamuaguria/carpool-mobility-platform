<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\RecordsAuthorisationRefusals;
use Cmp\Application\Shared\Authorisation\Role;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Infrastructure\Authorisation\EvidentialAuthorisationRefusals;
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
     * **Empty.** `SEC-055` ‡ makes an operation with no stated rule refused, and
     * no application service exists yet to state one for. `SADR-06` accepts the
     * consequence: *"Every operation needs an explicit rule before it works at
     * all."*
     *
     * A rule is added on the commit that adds the operation it governs — never
     * ahead of it, because a rule for an operation that does not exist permits
     * nothing and reviews as though something had been decided.
     */
    public static function policy(): AuthorisationPolicy
    {
        return AuthorisationPolicy::of([]);
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

        // ResolvesActorRoles is deliberately unbound. SEC-045 ‡ requires an
        // actor's entitlement to be evaluated against platform state, and the
        // state it would read — op_users and its role assignment — arrives with
        // FEAT-004 and FEAT-007. Binding a stub that returned an actor holding
        // no roles would look like an implementation and behave like one, and
        // ADM-187/ADM-191 forbid exactly that shape of placeholder.
        //
        // Nothing resolves an actor yet, because no interface surface exists to
        // need one.
    }
}
