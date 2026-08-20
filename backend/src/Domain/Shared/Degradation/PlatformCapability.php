<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Degradation;

use InvalidArgumentException;

/**
 * Something the platform offers, and how it behaves when what it needs is gone.
 *
 * `FRD-FR-255` requires the platform to *"determine which capabilities are
 * affected"* by an unavailability, which is only answerable if a capability is a
 * thing with a name and a stated dependence. This is that thing.
 *
 * ## Named in the platform's terms, never the provider's
 *
 * `API-090` forbids the dependency-unavailable branch naming the provider or
 * exposing its error, and `BE-150` forbids a port naming a supplier. The same
 * applies here with more force, because a capability name reaches a **client**
 * through `API-091` — *"degraded platform state shall be propagated so that the
 * client can disclose what remains available"*. `mapping` is a capability;
 * the company that supplies it is not the platform's to disclose.
 *
 * ## `FRD-FR-259` ‡ is a property of the capability, decided once
 *
 * *"The system shall withdraw a capability entirely, rather than degrade it,
 * where degrading it would compromise an absolute rule."*
 *
 * That cannot be decided at the moment of failure — under failure is exactly when
 * *"it will probably be fine"* gets decided. So each capability declares it when
 * it is registered, and {@see standingWhenSupportIsUnavailable()} is the only
 * place the consequence is computed.
 *
 * `UC-081` E1 gives the test: *"Seats, bookings and payments are never
 * approximated."* A capability that allocates a seat, confirms a booking or
 * resolves a payment is {@see essential()} and can only ever be withdrawn.
 * `UC-081` A1 gives the other side: tracking *"may continue without map
 * context"*, so tracking is not essential in this sense and may be marked.
 *
 * ## `BE-012` — this is where an absolute rule goes
 *
 * A capability declared essential cannot be marked, whatever any caller wants and
 * whatever any configuration says. There is no parameter and no setter that could
 * turn one into the other after construction.
 */
final class PlatformCapability
{
    private function __construct(
        private readonly string $name,
        private readonly bool $essential,
    ) {}

    /**
     * A capability that may be **marked** when its support is gone.
     *
     * `FRD-FR-256` ‡'s *"mark"*: it still does something real and the client is
     * told what is missing. `UC-081` A1's tracking-without-map-context is the
     * documented example.
     */
    public static function degradable(string $name): self
    {
        return new self(self::assertName($name), false);
    }

    /**
     * A capability that may only ever be **withdrawn** (`FRD-FR-259` ‡).
     *
     * Degrading it would compromise an absolute rule, so there is no reduced form
     * of it to offer. `UC-081` E1 names the three the documentation is explicit
     * about — seats, bookings and payments — and the register says of each
     * further capability which it is.
     */
    public static function essential(string $name): self
    {
        return new self(self::assertName($name), true);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * `FRD-FR-259` ‡: whether degrading this would compromise an absolute rule.
     */
    public function isEssential(): bool
    {
        return $this->essential;
    }

    /**
     * The one place `FRD-FR-256` ‡ and `FRD-FR-259` ‡ meet.
     *
     * `BE-010` puts a business rule in exactly one Domain component, and this is
     * it: nothing else in the platform decides withdrawn-against-marked, so a
     * caller cannot reach a different answer by reasoning about the cases.
     */
    public function standingWhenSupportIsUnavailable(): CapabilityStanding
    {
        return $this->essential ? CapabilityStanding::Withdrawn : CapabilityStanding::Marked;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    private static function assertName(string $name): string
    {
        // Lower-case and dot-separated, the same shape a policy key and an
        // authorisation capability take — the register then reads as a list of
        // things the platform offers rather than of identifiers.
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a capability name. API-091 propagates this to a client, so it is the '
                .'platform\'s own term for what it offers — never a provider, a class or a table.',
                $name,
            ));
        }

        return $name;
    }
}
