<?php

declare(strict_types=1);

namespace Tests\Domain\Degradation;

use Cmp\Application\Shared\Degradation\CapabilityRegister;
use Cmp\Application\Shared\Degradation\ReportPlatformHealth;
use Cmp\Application\Shared\Degradation\UnknownCapability;
use Cmp\Domain\Shared\Degradation\CapabilityDependence;
use Cmp\Domain\Shared\Degradation\CapabilityStanding;
use Cmp\Domain\Shared\Degradation\Kind;
use Cmp\Domain\Shared\Degradation\PlatformCapability;
use Cmp\Domain\Shared\Degradation\Support;
use InvalidArgumentException;
use Tests\Domain\Degradation\Doubles\StatedSupport;
use Tests\Domain\DomainTestCase;

/**
 * `UC-081` / `FRD-FR-255`–`260` — degraded operation.
 *
 * **Level 2** (`TC-029`): the whole of steps 1 to 4 is a computation over a
 * register and a set of observations, so this is the earliest level that can
 * verify it conclusively (`TC-033`). That the platform's **own** register says
 * what it says, and that `GET /health` serialises it, are levels 3 and 5.
 *
 * `FR-04` requires a negative test for every ‡ requirement. Three land here:
 * `FRD-FR-256` ‡ (never presented as working), `FRD-FR-258` ‡ (no assumption in
 * either direction) and `FRD-FR-259` ‡ (withdrawn entirely, never degraded).
 */
final class DegradedOperationTest extends DomainTestCase
{
    private const MAPPING = 'mapping';

    private const PAYMENT = 'payment';

    public function test_a_capability_whose_support_is_answering_is_available(): void
    {
        // UC-081 step 1 and 2, in the ordinary case: nothing is missing, so
        // nothing is affected.
        $health = $this->health(missing: []);

        self::assertSame(CapabilityStanding::Available, $health->standingOf('tracking'));
        self::assertTrue($health->now()->isFullyAvailable());
    }

    public function test_an_affected_capability_is_never_reported_as_working(): void
    {
        // Negative test for FRD-FR-256 ‡: "withdraw or mark an affected
        // capability rather than present it as working". Whichever of the two it
        // is, what it is not is Available.
        $health = $this->health(missing: [Support::service(self::MAPPING)]);

        foreach (['tracking', 'search'] as $affected) {
            self::assertNotSame(
                CapabilityStanding::Available,
                $health->standingOf($affected),
                'FRD-FR-256 ‡: '.$affected.' depends on mapping, which is gone.',
            );
            self::assertFalse($health->standingOf($affected)->mayBeOffered());
        }
    }

    public function test_a_degradable_capability_is_marked_and_an_essential_one_is_withdrawn(): void
    {
        // FRD-FR-256 ‡'s two words, and FRD-FR-259 ‡ choosing between them.
        // UC-081 A1: with mapping unavailable, tracking "may continue without map
        // context" — marked. UC-081 E1: seats, bookings and payments are never
        // approximated — withdrawn.
        $health = $this->health(missing: [Support::service(self::MAPPING)]);

        self::assertSame(CapabilityStanding::Marked, $health->standingOf('tracking'));
        self::assertSame(CapabilityStanding::Withdrawn, $health->standingOf('search'));
    }

    public function test_an_essential_capability_can_never_be_marked(): void
    {
        // Negative test for FRD-FR-259 ‡: "withdraw a capability entirely, rather
        // than degrade it, where degrading it would compromise an absolute rule".
        // Asked of the rule directly, so it holds for every capability that will
        // ever be declared essential rather than only for the two above.
        $essential = PlatformCapability::essential('bookings');

        self::assertTrue($essential->isEssential());
        self::assertSame(CapabilityStanding::Withdrawn, $essential->standingWhenSupportIsUnavailable());
        self::assertNotSame(CapabilityStanding::Marked, $essential->standingWhenSupportIsUnavailable());
    }

    public function test_an_unobservable_dependency_is_treated_as_missing_and_not_as_present(): void
    {
        // Negative test for FRD-FR-258 ‡: an unknown outcome is not resolved by
        // assumption "in either direction", and reporting a capability available
        // because nothing could be checked is one of the two directions.
        // SRS-REQ-113 forbids synthesising the result that would produce it.
        $health = $this->health(missing: [Support::service('verification')]);

        self::assertSame(CapabilityStanding::Withdrawn, $health->standingOf('identity_checks'));
    }

    public function test_normal_behaviour_resumes_without_a_reset(): void
    {
        // FRD-FR-260, step 5: "On restoration, normal behaviour resumes." The
        // cheapest way to fail this is a latch nobody clears, so the same
        // register is asked twice through one observer whose answer changes —
        // and the second answer is the new one, with no reset, restart or
        // operator action between them.
        $observer = new StatedSupport([Support::service(self::MAPPING)]);
        $health = new ReportPlatformHealth($this->register(), $observer);

        self::assertSame(CapabilityStanding::Withdrawn, $health->standingOf('search'));

        $observer->restoreEverything();

        self::assertSame(CapabilityStanding::Available, $health->standingOf('search'));
        self::assertTrue($health->now()->isFullyAvailable());
    }

    public function test_health_reports_what_is_unavailable_and_what_remains(): void
    {
        // FRD-FR-257 / API-091: both halves. The list carries every declared
        // capability, so a client reads what remains from the same place it
        // reads what does not — rather than subtracting one from a set it has to
        // know separately.
        $health = $this->health(missing: [Support::service(self::PAYMENT)])->now();

        self::assertSame(
            ['tracking', 'search', 'identity_checks', 'settlement'],
            array_keys($health->standings()),
        );
        self::assertSame(['tracking', 'search', 'identity_checks'], $health->offered());
        self::assertFalse($health->isFullyAvailable());
        self::assertSame(CapabilityStanding::Withdrawn, $health->standings()['settlement']);
    }

    public function test_the_missing_list_names_the_platforms_terms_and_no_provider(): void
    {
        // API-090's reasoning, at a surface §9.1 makes reachable by anyone: a
        // dependency is named by what the platform calls it. The type refuses a
        // name that is not in that shape.
        $health = $this->health(missing: [Support::service(self::PAYMENT)])->now();

        self::assertSame(
            [['kind' => Kind::Service->value, 'name' => self::PAYMENT]],
            $health->missingDescribed(),
        );
    }

    public function test_a_capability_affected_by_two_things_names_both(): void
    {
        // FRD-FR-257 tells the actor what is unavailable, and an operator told
        // about one of two comes back for the second. API-079 takes the same
        // position for a caller's invalid request.
        $dependence = CapabilityDependence::of(
            PlatformCapability::essential('settlement'),
            [Support::service(self::PAYMENT), Support::policyValue('money.precision')],
        );

        $missing = $dependence->missingFrom([
            Support::service(self::PAYMENT),
            Support::policyValue('money.precision'),
            Support::service(self::MAPPING),
        ]);

        self::assertCount(2, $missing);
        self::assertSame([self::PAYMENT, 'money.precision'], array_map(
            static fn (Support $support): string => $support->name(),
            $missing,
        ));
    }

    public function test_a_service_and_a_policy_value_of_the_same_name_are_different_dependencies(): void
    {
        // Kind is part of the identity. Without that, a policy key that happened
        // to share a name with a service would silently satisfy it — and
        // FRD-FR-257 would then tell an operator the wrong thing is missing.
        self::assertFalse(Support::service('mapping')->equals(Support::policyValue('mapping')));
        self::assertTrue(Support::service('mapping')->equals(Support::service('mapping')));
    }

    public function test_an_undeclared_capability_raises_rather_than_reporting_available(): void
    {
        // The safe answer to "may I offer this?" is never a cheerful yes about
        // something nobody declared — SEC-055 ‡'s reasoning about an unstated
        // authorisation rule, applied to a capability.
        $this->expectException(UnknownCapability::class);
        $this->expectExceptionMessageMatches('/not a declared capability/');

        $this->health(missing: [])->standingOf('wallet');
    }

    public function test_a_capability_declared_twice_is_refused(): void
    {
        // Two entries would let two answers exist for FRD-FR-255's question, and
        // which a caller got would depend on which was found first.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declared twice/');

        CapabilityRegister::of(
            CapabilityDependence::of(PlatformCapability::essential('search'), [Support::service('a.b')]),
            CapabilityDependence::of(PlatformCapability::degradable('search'), [Support::service('c.d')]),
        );
    }

    public function test_a_capability_depending_on_nothing_is_refused(): void
    {
        // A register whose only purpose is to answer what is affected has no
        // answer to give about something nothing can affect.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/depends on nothing/');

        CapabilityDependence::of(PlatformCapability::essential('search'), []);
    }

    public function test_a_dependency_shared_by_two_capabilities_is_observed_once(): void
    {
        // Two observations of one thing could disagree, and FRD-FR-255 would then
        // report one capability affected and another not, by the same cause.
        $observer = new StatedSupport([]);
        $health = new ReportPlatformHealth($this->register(), $observer);

        $health->now();

        // `mapping` is needed by both tracking and search; `payment` by
        // settlement; `verification` by identity_checks.
        self::assertSame(3, $observer->observationCount());
    }

    /**
     * A register with one capability of each shape the requirements name.
     */
    private function register(): CapabilityRegister
    {
        return CapabilityRegister::of(
            // UC-081 A1: "tracking may continue without map context".
            CapabilityDependence::of(
                PlatformCapability::degradable('tracking'),
                [Support::service(self::MAPPING)],
            ),
            // UC-081 A1: search is withdrawn when mapping is unavailable.
            CapabilityDependence::of(
                PlatformCapability::essential('search'),
                [Support::service(self::MAPPING)],
            ),
            // UC-081 A4: "no user or vehicle is treated as verified in its
            // absence" — so there is no reduced form to mark.
            CapabilityDependence::of(
                PlatformCapability::essential('identity_checks'),
                [Support::service('verification')],
            ),
            // UC-081 E1: payments are never approximated.
            CapabilityDependence::of(
                PlatformCapability::essential('settlement'),
                [Support::service(self::PAYMENT)],
            ),
        );
    }

    /**
     * @param  list<Support>  $missing
     */
    private function health(array $missing): ReportPlatformHealth
    {
        return new ReportPlatformHealth($this->register(), new StatedSupport($missing));
    }
}
