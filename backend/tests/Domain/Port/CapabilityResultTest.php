<?php

declare(strict_types=1);

namespace Tests\Domain\Port;

use Cmp\Application\Shared\Failure\FailureBranch;
use Cmp\Application\Shared\Port\UnavailableCapability;
use Cmp\Domain\Shared\Port\CapabilityOutcome;
use Cmp\Domain\Shared\Port\CapabilityResult;
use InvalidArgumentException;
use LogicException;
use Tests\Domain\DomainTestCase;

/**
 * CMP-IMP-030 — the capability result type.
 *
 * Level 2 (`TC-029` ‡). `BE-163` requires every port to have a test adapter
 * permitting **every** result to be exercised; this exercises all four at the
 * result type itself, which is what any such adapter would return.
 */
final class CapabilityResultTest extends DomainTestCase
{
    public function test_a_port_returns_one_of_exactly_four_outcomes(): void
    {
        // BE-151. SRS-REQ-113 forbids synthesising a result: there is no
        // "Assumed".
        self::assertCount(4, CapabilityOutcome::cases());
        self::assertSame(
            ['Verified', 'Reported', 'Unavailable', 'Rejected'],
            array_map(static fn (CapabilityOutcome $o): string => $o->name, CapabilityOutcome::cases()),
        );
    }

    public function test_a_verified_result_yields_its_value_as_fact(): void
    {
        $result = CapabilityResult::verified('confirmation-1');

        self::assertTrue($result->isVerified());
        self::assertSame('confirmation-1', $result->verifiedValue());
    }

    public function test_a_reported_value_cannot_be_read_as_a_verified_fact(): void
    {
        // Negative test for SRS-REQ-106 / BADR-11: "the provider reported X" must
        // never become "X is true". BADR-11 rejected a plain-value port for
        // erasing exactly this distinction.
        $result = CapabilityResult::reported('the provider says it went through');

        self::assertTrue($result->isReported());
        self::assertSame('the provider says it went through', $result->reportedValue());

        $this->expectException(LogicException::class);
        $result->verifiedValue();
    }

    public function test_a_verified_value_cannot_be_read_as_merely_reported(): void
    {
        // The distinction runs both ways: a caller must say which it believes it
        // is holding.
        $this->expectException(LogicException::class);

        CapabilityResult::verified('confirmation-1')->reportedValue();
    }

    public function test_an_unavailable_result_carries_no_value_at_all(): void
    {
        // BE-152 ‡ / SRS-REQ-113. A type that could yield a value for an answer
        // that never arrived would be an invitation to synthesise one.
        $result = CapabilityResult::unavailable('payment-confirmation');

        self::assertTrue($result->isUnavailable());
        self::assertFalse($result->isVerified());
        self::assertFalse($result->isReported());
        self::assertFalse($result->isRejected());
        self::assertSame('payment-confirmation', $result->unavailableCapability());
    }

    public function test_an_unavailable_result_refuses_to_yield_a_verified_value(): void
    {
        // Negative test for BE-152 ‡ / FRD-FR-258 ‡: an unknown outcome is not
        // resolved by assumption in either direction.
        $this->expectException(LogicException::class);

        CapabilityResult::unavailable('payment-confirmation')->verifiedValue();
    }

    public function test_an_unavailable_result_must_name_the_capability(): void
    {
        // FRD-FR-257: the actor is told what is unavailable. API-090: never the
        // provider behind it.
        $this->expectException(InvalidArgumentException::class);

        CapabilityResult::unavailable('   ');
    }

    public function test_a_rejected_result_carries_the_platform_reason_not_the_provider_error(): void
    {
        // BE-154: an adapter translates without leaking the provider
        // representation.
        $result = CapabilityResult::rejected('payment.declined_by_provider');

        self::assertTrue($result->isRejected());
        self::assertSame('payment.declined_by_provider', $result->rejectionReason());
    }

    public function test_an_unavailable_capability_maps_to_the_branch_that_decides_nothing(): void
    {
        // BE-152 ‡ / API-089 ‡.
        $failure = UnavailableCapability::asFailure(CapabilityResult::unavailable('address-lookup'));

        self::assertSame(FailureBranch::DependencyUnavailable, $failure->branch());
        self::assertTrue($failure->nothingWasDecided());
        self::assertSame('address-lookup', $failure->capability());
    }

    public function test_a_rejection_is_not_mapped_to_a_failure_by_the_application(): void
    {
        // Negative test for BE-156 ‡. A helper that turned a provider's rejection
        // into a business refusal would be an adapter deciding a business
        // outcome — in the application layer, for every port at once.
        $this->expectException(LogicException::class);

        UnavailableCapability::asFailure(CapabilityResult::rejected('payment.declined_by_provider'));
    }

    public function test_a_reported_result_is_not_mapped_to_a_failure_either(): void
    {
        $this->expectException(LogicException::class);

        UnavailableCapability::asFailure(CapabilityResult::reported('something'));
    }
}
