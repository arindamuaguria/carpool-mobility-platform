<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Degradation\CapabilityRegister;
use Cmp\Application\Shared\Degradation\ObservesSupport;
use Cmp\Application\Shared\Degradation\ReportPlatformHealth;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Degradation\CapabilityDependence;
use Cmp\Domain\Shared\Degradation\PlatformCapability;
use Cmp\Domain\Shared\Degradation\Support;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Infrastructure\Degradation\ObservedSupport;
use Cmp\Infrastructure\User\Argon2idAuthenticationMaterial;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The one place a capability and its dependence are declared.
 *
 * `NFR-034` ‡ requires **all** supporting services covered by a degraded mode,
 * which a reviewer can only check against a list. This is the list, and the rule
 * it follows is the one `PolicyServiceProvider` and `AuthorisationServiceProvider`
 * already follow: **an entry is added on the commit that builds the capability it
 * describes**, never ahead of it. A degraded mode for something no client can call
 * reviews as though something had been decided.
 */
final class DegradationServiceProvider extends ServiceProvider
{
    /**
     * The platform's capabilities, and what each needs.
     *
     * **Two entries**, both added on 2026-08-20 with the operations they cover.
     * Both are `essential()` under `FRD-FR-259` ‡ — see {@see capabilities()}.
     *
     * `UC-081`'s own examples are **absent**, and each for a reason already on the
     * record rather than a new one:
     *
     * | Capability `UC-081` names | Why it is not declared |
     * |---|---|
     * | Search and ride publication (`A1`) | FEAT-009 and FEAT-011 are unbuilt; `ARCH-OQ-001`'s overlap algorithm is open. |
     * | Live tracking (`A1`) | FEAT-019 is unbuilt; `BAD-DEC-021` and `FRD-OQ-009` are open. |
     * | Payment initiation and verification (`A2`) | `BAD-DEP-004` leaves the PSP unselected; `T5` monetary precision is unresolved, so no money column exists. |
     * | Notification and message delivery (`A3`) | FEAT-023 and FEAT-022 are unbuilt; the port declares no operation (`CMP-IMP-334`). |
     * | Identity and vehicle verification (`A4`) | `BAD-DEC-005` is open and `BAD-DEP-005` leaves the provider unselected. |
     * | Phone verification delivery | `CC-034` — **no channel is specified**; CMP-DOC-16 §0.6.1 declines to specify one. |
     *
     * Every one is blocked on something outside this file. None is stubbed:
     * `ADM-187`/`ADM-191` forbid a placeholder for a withheld item, and a
     * capability that reported a degraded mode for behaviour nothing performs
     * would be exactly that.
     */
    public static function declaredCapabilities(): CapabilityRegister
    {
        return CapabilityRegister::of(...self::capabilities());
    }

    /**
     * The two capabilities the platform actually offers today.
     *
     * Both are declared `essential()`, so `FRD-FR-259` ‡ permits only withdrawal
     * and never marking:
     *
     * - **`session`** reads `SEC-039` ‡'s lifetime on every request and
     *   `SEC-049`'s limit at establishment. A session served with a guessed
     *   lifetime is a session with no bound, which is what `SEC-039` ‡ exists to
     *   prevent; a limit guessed at is `SEC-243` ‡ not being enforced. Neither has
     *   a reduced form, so there is nothing to mark.
     * - **`authentication.material`** reads `SEC-030`'s three Argon2id costs.
     *   `SEC-244` ‡ already refuses to hash beneath its floor; a "degraded"
     *   authentication that hashed anyway would compromise `SEC-028` ‡, and
     *   `UC-081` E1 is the general form of that — *"seats, bookings and payments
     *   are never approximated"*, and neither is a credential.
     *
     * All five policy values are **declared and unset** (`SEC-031` for three of
     * them, `BADR-12`'s operator action for the other two), so both capabilities
     * report **withdrawn** today. That is not a defect being surfaced as one: it
     * is `SRS-REQ-158` and `NFR-034` ‡ agreeing, and the alternative — a health
     * indication reporting green while every session resolution raises — is what
     * `FRD-FR-256` ‡ forbids.
     *
     * @return list<CapabilityDependence>
     */
    public static function capabilities(): array
    {
        return [
            CapabilityDependence::of(
                PlatformCapability::essential('session'),
                [
                    Support::policyValue(ResolveSession::LIFETIME_KEY),
                    Support::policyValue(PolicyServiceProvider::concurrentSessionLimit()->name()),
                ],
            ),
            CapabilityDependence::of(
                PlatformCapability::essential('authentication.material'),
                [
                    Support::policyValue(Argon2idAuthenticationMaterial::MEMORY_KEY),
                    Support::policyValue(Argon2idAuthenticationMaterial::ITERATIONS_KEY),
                    Support::policyValue(Argon2idAuthenticationMaterial::LANES_KEY),
                ],
            ),
        ];
    }

    public function register(): void
    {
        $this->app->singleton(CapabilityRegister::class, static fn (): CapabilityRegister => self::declaredCapabilities());

        $this->app->singleton(
            ObservesSupport::class,
            static fn (Application $app): ObservedSupport => new ObservedSupport(
                $app->make(PolicyStore::class),
                $app->make(PolicyRegister::class),
            ),
        );

        // FRD-FR-260: bound fresh rather than as a singleton holding state, and
        // nothing it produces is cached. A restored dependency is reflected on
        // the next call with no reset — the latch nobody clears is the classic
        // way this requirement is failed.
        $this->app->bind(
            ReportPlatformHealth::class,
            static fn (Application $app): ReportPlatformHealth => new ReportPlatformHealth(
                $app->make(CapabilityRegister::class),
                $app->make(ObservesSupport::class),
            ),
        );
    }
}
