<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Cmp\Domain\Payment\Port\PaymentVerificationPort;
use Cmp\Domain\Ride\Port\MappingPort;
use Cmp\Domain\SafetyIncident\Port\EmergencyDispatchPort;
use Cmp\Domain\Shared\Notification\NotificationPort;
use Cmp\Domain\Shared\Port\Port;
use Cmp\Domain\User\Port\IdentityVerificationPort;
use Illuminate\Support\ServiceProvider;

/**
 * The one place a port is bound to an adapter.
 *
 * `BE-162`: an adapter is substitutable **without change above
 * `Infrastructure`**. That is only true if the binding lives in one place;
 * bindings scattered through providers make substitution a search.
 *
 * `BE-153` ‡: no provider type appears above the adapter — enforced by
 * PortRulesTest, not by this file's good intentions.
 *
 * **No port is bound.** `BE-161` records the five providers as
 * `[TBD – Business Decision Required]` except mapping and notification, which
 * are directed; and none of the five ports declares an operation yet, so there
 * is nothing for an adapter to implement.
 *
 * @see ports() for the register and what blocks each.
 */
final class PortServiceProvider extends ServiceProvider
{
    /**
     * The five ports of CMP-DOC-09 §12, and what blocks an adapter for each.
     *
     * `BE-149`: a port is declared for each capability the platform does not
     * itself provide. `BE-164`: the emergency dispatch port exists so that its
     * absence is visible rather than assumed.
     *
     * @return array<class-string<Port>, string>
     */
    public static function ports(): array
    {
        return [
            PaymentVerificationPort::class => 'BE-161: the payment provider is [TBD – Business Decision Required]. FEAT-016 adds the operations.',
            IdentityVerificationPort::class => 'BAD-DEC-005 (verification policy) is open, so what is verified is undecided, and BE-161 leaves the provider unselected.',
            NotificationPort::class => 'The provider is directed (BE-161); CMP-DOC-01 §2 names it. The operations belong to CMP-IMP-334 in FEAT-023.',
            MappingPort::class => 'The provider is directed (BE-161). What is asked of it follows from route matching, and ARCH-OQ-001 is open.',
            EmergencyDispatchPort::class => 'WITHHELD. BAD-DEC-011 is open and no response capability is staffed (BAD-RISK-005, GAP-004). Nothing may implement this port.',
        ];
    }

    /**
     * Ports for which an adapter must never be registered while the reason
     * stands, as distinct from ports simply awaiting one.
     *
     * @return list<class-string<Port>>
     */
    public static function withheldPorts(): array
    {
        return [EmergencyDispatchPort::class];
    }

    public function register(): void
    {
        // Intentionally empty. See ports(): no port declares an operation yet,
        // and one of the five must never be bound at all.
    }
}
