<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Degradation;

use InvalidArgumentException;

/**
 * A capability, everything it needs, and how it stands given what is missing.
 *
 * `FRD-FR-255`, step 2 of `UC-081`: *"The platform determines which capabilities
 * are affected."* This is the determination — one capability's worth of it — and
 * it is the whole of the arithmetic, so nothing else in the platform performs it.
 *
 * ## What is deliberately absent
 *
 * There is no cached standing and no latch. `FRD-FR-260` requires normal
 * behaviour to resume when a supporting service is restored, and the cheapest way
 * to fail that is to record *"unavailable"* somewhere and never clear it — the
 * classic circuit breaker that stays open because nothing closes it. Standing is
 * computed from what is missing **at the moment it is asked**, so restoration
 * needs no reset, no restart and no operator action.
 *
 * There is also no way to construct one with no support at all. A capability that
 * depends on nothing cannot be affected by anything, so it has no business in a
 * register whose only purpose is to answer what is affected.
 */
final class CapabilityDependence
{
    /** @var list<Support> */
    private readonly array $needs;

    /**
     * @param  list<Support>  $needs
     */
    private function __construct(
        private readonly PlatformCapability $capability,
        array $needs,
    ) {
        $this->needs = $needs;
    }

    /**
     * @param  list<Support>  $needs  everything this capability requires
     */
    public static function of(PlatformCapability $capability, array $needs): self
    {
        if ($needs === []) {
            throw new InvalidArgumentException(sprintf(
                'FRD-FR-255 determines which capabilities are affected by an unavailability. "%s" depends on '
                .'nothing, so nothing can affect it and the register has no answer to give about it.',
                $capability->name(),
            ));
        }

        return new self($capability, array_values($needs));
    }

    public function capability(): PlatformCapability
    {
        return $this->capability;
    }

    /**
     * @return list<Support>
     */
    public function needs(): array
    {
        return $this->needs;
    }

    /**
     * What of this capability's support is missing, from what is missing overall.
     *
     * Every one of them, not the first: `FRD-FR-257` tells the actor what is
     * unavailable, and an operator reading *"mapping"* when messaging is also
     * gone fixes one thing and comes back. `API-079` takes the same position for
     * a caller's invalid request.
     *
     * @param  list<Support>  $missing  what is not answering, platform-wide
     * @return list<Support>
     */
    public function missingFrom(array $missing): array
    {
        $affecting = [];

        foreach ($this->needs as $need) {
            foreach ($missing as $absent) {
                if ($need->equals($absent)) {
                    $affecting[] = $need;

                    break;
                }
            }
        }

        return $affecting;
    }

    /**
     * `FRD-FR-256` ‡ / `FRD-FR-259` ‡: how this capability stands.
     *
     * @param  list<Support>  $missing  what is not answering, platform-wide
     */
    public function standingGiven(array $missing): CapabilityStanding
    {
        return $this->missingFrom($missing) === []
            ? CapabilityStanding::Available
            : $this->capability->standingWhenSupportIsUnavailable();
    }
}
