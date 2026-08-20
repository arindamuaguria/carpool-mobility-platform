<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Degradation;

use Cmp\Domain\Shared\Degradation\CapabilityDependence;
use Cmp\Domain\Shared\Degradation\PlatformCapability;
use Cmp\Domain\Shared\Degradation\Support;
use InvalidArgumentException;

/**
 * The closed declaration of what the platform offers and what each offering
 * needs.
 *
 * `NFR-034` ‡ requires *"all supporting services covered by a degraded mode"*,
 * which is checkable only against a list of what depends on what. This is that
 * list, and it is a **value** rather than a global — the platform declares its own
 * at the composition root, and a test constructs its own, so exercising the
 * mechanism never requires adding a capability to the platform's.
 *
 * ## Closed, and each absent entry names its blocker
 *
 * `FRD-FR-255` can only report on capabilities that exist. Most of `UC-081`'s
 * examples — search, publication, tracking, payment, messaging — are unbuilt, and
 * a register entry for a capability nothing provides would report a degraded mode
 * for something no client can call. The composition root states which are absent
 * and why, in the same shape `PortServiceProvider::ports()` and
 * `AuthorisationServiceProvider::roles()` use.
 *
 * ## A capability is declared once
 *
 * Two entries for one name would let two answers exist for `FRD-FR-255`'s
 * question, and the one a caller got would depend on which it found first.
 */
final class CapabilityRegister
{
    /** @var array<string, CapabilityDependence> */
    private readonly array $entries;

    private function __construct(CapabilityDependence ...$entries)
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $name = $entry->capability()->name();

            if (array_key_exists($name, $indexed)) {
                throw new InvalidArgumentException(sprintf(
                    'FRD-FR-255 determines which capabilities are affected, and "%s" is declared twice — so '
                    .'there would be two answers and no way to tell which is the platform\'s.',
                    $name,
                ));
            }

            $indexed[$name] = $entry;
        }

        $this->entries = $indexed;
    }

    public static function of(CapabilityDependence ...$entries): self
    {
        return new self(...$entries);
    }

    /**
     * @return list<CapabilityDependence>
     */
    public function all(): array
    {
        return array_values($this->entries);
    }

    public function declares(string $name): bool
    {
        return array_key_exists($name, $this->entries);
    }

    /**
     * @throws InvalidArgumentException where the register does not declare it
     */
    public function dependenceOf(PlatformCapability $capability): CapabilityDependence
    {
        return $this->entries[$capability->name()] ?? throw new InvalidArgumentException(sprintf(
            'FRD-FR-255: "%s" is not a declared capability, so nothing can be said about what affects it. '
            .'A capability is declared here on the commit that builds it.',
            $capability->name(),
        ));
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Everything anything in this register depends on, each named once.
     *
     * What {@see ReportPlatformHealth} asks {@see ObservesSupport} about — asked
     * once per dependency rather than once per capability that needs it, because
     * two capabilities sharing a supporting service must not produce two
     * observations that could disagree.
     *
     * @return list<Support>
     */
    public function supports(): array
    {
        $seen = [];

        foreach ($this->entries as $entry) {
            foreach ($entry->needs() as $need) {
                $seen[$need->kind()->value.' '.$need->name()] = $need;
            }
        }

        return array_values($seen);
    }
}
