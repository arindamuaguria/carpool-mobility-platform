<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Application\Shared\Authorisation\AuthorisationRefusal;
use Cmp\Application\Shared\Configuration\DeliveredValue;
use Cmp\Application\Shared\Configuration\ServeConfiguration;
use Cmp\Application\Shared\Idempotency\IdempotencyRefusal;
use Cmp\Application\User\EstablishmentRefusal;
use Cmp\Application\User\ResolveSession;
use Cmp\Application\User\SessionRefusal;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\Refusal\RefusalReason;
use Cmp\Domain\Shared\StateMachine\StateMachineRefusal;
use Cmp\Domain\User\EmergencyContactRefusal;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * The one place CMP-DOC-10 §14.2's register is declared.
 *
 * `API-187` ‡ makes this resource the only place a client obtains a policy value
 * and `API-196` the only way one is delivered, so what it delivers is a contract
 * — and a contract a reviewer has to be able to read in one place.
 */
final class ConfigurationServiceProvider extends ServiceProvider
{
    /**
     * The values §14.2 delivers, and what blocks each that is absent.
     *
     * **Two of eight**, both added with the code that supplies them.
     *
     * | §14.2 value | Delivered? |
     * |---|---|
     * | Session lifetime bound (`API-104`) | **Yes** — `SEC-039` ‡'s policy value |
     * | Refusal reason identifier set (`AADR-14`) | **Yes** — derived from the platform's own reasons |
     * | Position reporting cadence (`ARCH-093`, `MOB-135`) | No — FEAT-019 is unbuilt |
     * | Position staleness bound (`ARCH-094`, `API-161`) | No — FEAT-019 is unbuilt |
     * | Presentation cache validity per category (`ARCH-069`) | No — no category is declared; `ARCH-069`'s set arrives with the resources it applies to |
     * | Search result limit (`NFR-020`) | No — FEAT-011 is unbuilt and `ARCH-OQ-001` is open |
     * | Page size default and maximum (`API-115`) | No — `CMP-IMP-470` cursor paging is blocked |
     * | Client retry and backoff policy (`MOB-051`) | No — Mobile is unbuilt; `CMP-IMP-059` depends on documented Mobile requirements |
     *
     * None is stubbed and none is served as a guessed default. `API-193` gives a
     * client a conservative default for every value *"applied only until its first
     * successful fetch"*, and a value the platform invented would displace a
     * default the client documented — which is the one thing worse than not
     * serving it.
     *
     * @return list<DeliveredValue>
     */
    public static function delivered(): array
    {
        return [
            // API-104: the bound a client needs in order to know when to refresh.
            // Public — SEC-039 ‡'s figure is an operator's decision about the
            // platform's posture, not a fact about its state, so API-195 is met.
            DeliveredValue::fromPolicy(
                ServeConfiguration::SESSION_LIFETIME,
                PolicyServiceProvider::sessionLifetime(),
                public: true,
            ),

            // AADR-14. Public, and necessarily so: API-083 has the client present
            // its own text keyed by the identifier, and a client that cannot
            // authenticate yet still receives refusals it must show.
            DeliveredValue::derived(ServeConfiguration::REFUSAL_REASONS, public: true),
        ];
    }

    /**
     * Every reason identifier the platform can return (`AADR-14`).
     *
     * `AADR-14`'s stated consequence — *"reasons are enumerable and testable"* —
     * is only true if something enumerates them, and this is it.
     * `ConfigurationRulesTest` fails the build if a `RefusalReason` exists that is
     * not listed here, so the set cannot silently fall behind the platform.
     *
     * `SEC-049`'s concurrent limit and the three Argon2id costs are **not**
     * delivered: none appears in §14.2's list, and `API-195` keeps a value that
     * discloses platform state out of the public subset. How many sessions a user
     * may hold and how expensive a hash is are both facts about the platform's
     * configuration that no client needs in order to behave correctly.
     *
     * @return list<RefusalReason>
     */
    public static function refusalReasons(): array
    {
        return [
            // SEC-069 ‡ / API-107: one identifier, whatever the cause.
            AuthorisationRefusal::NotAvailableToYou,

            // SEC-048 ‡: one case, so terminated, expired and unknown stay
            // indistinguishable.
            SessionRefusal::NotUsable,

            // UC-048 — the first refusals on this surface decided on
            // business grounds rather than on a session, an idempotency key
            // or a lifecycle. AADR-14: a reason the platform can return and
            // the register does not deliver is a reason the client has no
            // text for.
            ...EmergencyContactRefusal::cases(),

            ...EstablishmentRefusal::cases(),
            ...IdempotencyRefusal::cases(),
            ...StateMachineRefusal::cases(),
        ];
    }

    public function register(): void
    {
        $this->app->bind(
            ServeConfiguration::class,
            static fn (Application $app): ServeConfiguration => new ServeConfiguration(
                self::delivered(),
                self::refusalReasons(),
                $app->make(PolicyStore::class),
            ),
        );
    }

    /**
     * Named so that a reader of {@see delivered()} can see the key behind the
     * value without opening another file.
     */
    public static function sessionLifetimeKey(): string
    {
        return ResolveSession::LIFETIME_KEY;
    }
}
