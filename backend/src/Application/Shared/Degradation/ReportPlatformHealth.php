<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Degradation;

use Cmp\Domain\Shared\Degradation\CapabilityStanding;
use Cmp\Domain\Shared\Degradation\Support;

/**
 * `CMP-IMP-034` area / `BE-203` — the whole of `UC-081` steps 1 to 4.
 *
 * | Step | Statement | Where |
 * |---|---|---|
 * | 1. detect unavailability | `FRD-FR-255` | {@see ObservesSupport} |
 * | 2. determine what is affected | `FRD-FR-255` | `CapabilityDependence::missingFrom()` |
 * | 3. withdraw or mark, never present as working | `FRD-FR-256` ‡, `FRD-FR-259` ‡ | `PlatformCapability::standingWhenSupportIsUnavailable()` |
 * | 4. tell the actor what is unavailable and what remains | `FRD-FR-257`, `API-091` | {@see PlatformHealth} |
 *
 * ## Not an `ApplicationService`, and why that is not an exemption
 *
 * `SEC-055` ‡ refuses an operation with no stated rule, and `API-095` ‡ requires
 * a session for every operation outside CMP-DOC-10 §9.1. **§9.1 names platform
 * health among its five**, on `BE-203`'s authority, so this is one of the
 * operations that has no actor to authorise — the same position `VersionsController`
 * is in, and for the same documented reason.
 *
 * Health is also the one thing that must answer **when the platform is degraded**.
 * An authorisation evaluation reads a policy value; a policy value may be the
 * thing that is missing; and a health endpoint that failed because the platform
 * was unhealthy would be the least useful object in the system.
 *
 * ## Step 5 — `FRD-FR-260`, and why nothing here implements it
 *
 * *"On restoration, normal behaviour resumes and any deferred work proceeds."*
 *
 * Resumption needs no code, and that is a design decision rather than an
 * omission: **no standing is stored or cached anywhere**. Every answer below is
 * computed from what is missing at the moment it is asked, so a restored service
 * is reflected on the next request with no reset, no restart and no operator
 * action. `DegradedOperationTest` asserts exactly that, because the cheapest way
 * to fail `FRD-FR-260` is a latch nobody clears.
 *
 * The *"deferred work"* half belongs to the job store (`BE-142`, `BE-145`), which
 * already retains queued work and its availability instant. Nothing defers work
 * on a capability today, because no capability in the register has anything to
 * defer — the two it holds are refused outright rather than queued. That arrives
 * with the first capability that queues something.
 */
final class ReportPlatformHealth
{
    public function __construct(
        private readonly CapabilityRegister $register,
        private readonly ObservesSupport $observer,
    ) {}

    public function now(): PlatformHealth
    {
        $missing = $this->missing();
        $standings = [];

        foreach ($this->register->all() as $dependence) {
            $standings[$dependence->capability()->name()] = $dependence->standingGiven($missing);
        }

        return PlatformHealth::of($standings, $missing);
    }

    /**
     * Whether one capability may be offered right now (`FRD-FR-256` ‡).
     *
     * For a caller that is about to do something rather than about to report.
     * It runs the same computation as {@see now()}, so it cannot report as usable
     * something health would show as withdrawn.
     */
    public function standingOf(string $capability): CapabilityStanding
    {
        return $this->now()->standings()[$capability]
            ?? throw new UnknownCapability(sprintf(
                'FRD-FR-255: "%s" is not a declared capability. Nothing can be said about whether it is '
                .'affected, and answering "available" would be presenting an unknown as working.',
                $capability,
            ));
    }

    /**
     * What is not answering, asked once per dependency.
     *
     * @return list<Support>
     */
    private function missing(): array
    {
        $missing = [];

        foreach ($this->register->supports() as $support) {
            if (! $this->observer->isAnswering($support)) {
                $missing[] = $support;
            }
        }

        return $missing;
    }
}
