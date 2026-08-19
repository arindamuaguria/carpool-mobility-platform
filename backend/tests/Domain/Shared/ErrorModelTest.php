<?php

declare(strict_types=1);

namespace Tests\Domain\Shared;

use Cmp\Application\Shared\CorrelationIdentity;
use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Failure\DependencyUnavailable;
use Cmp\Application\Shared\Failure\FailureBranch;
use Cmp\Application\Shared\Failure\FieldError;
use Cmp\Application\Shared\Failure\InternalFault;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Domain\Shared\Refusal\BusinessRefusal;
use Cmp\Domain\Shared\Refusal\RefusalKind;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Domain\DomainTestCase;
use Tests\Domain\Shared\Doubles\TestRefusalReason;

/**
 * CMP-IMP-034 — the four-class backend error model.
 *
 * Level 2 (`TC-029` ‡): no database, no framework, no network.
 */
final class ErrorModelTest extends DomainTestCase
{
    #[Test]
    public function an_invalid_request_reports_every_offending_field_not_only_the_first(): void
    {
        // API-079.
        $failure = new InvalidRequest([
            new FieldError('origin', 'field.required', 'An origin is required.'),
            new FieldError('destination', 'field.required', 'A destination is required.'),
            new FieldError('departsAt', 'field.malformed', 'A departure instant is required.'),
        ]);

        self::assertSame(FailureBranch::InvalidRequest, $failure->branch());
        self::assertCount(3, $failure->fieldErrors());
        self::assertSame(
            ['origin', 'destination', 'departsAt'],
            array_map(static fn (FieldError $e): string => $e->field(), $failure->fieldErrors()),
        );
    }

    #[Test]
    public function an_invalid_request_cannot_be_raised_without_naming_an_offending_field(): void
    {
        // Negative test for API-078: the body identifies each offending field.
        $this->expectException(InvalidArgumentException::class);

        new InvalidRequest([]);
    }

    #[Test]
    public function a_business_refusal_carries_a_stable_identifier_and_a_presentation_default(): void
    {
        // API-081 ‡, API-082 ‡.
        $failure = new BusinessRefused(TestRefusalReason::SeatsNoLongerAvailable);

        self::assertSame(FailureBranch::BusinessRefusal, $failure->branch());
        self::assertSame('booking.seats_no_longer_available', $failure->identifier());
        self::assertNotSame('', $failure->defaultText());
    }

    #[Test]
    public function a_business_refusal_distinguishes_a_state_conflict_from_a_rule_declining(): void
    {
        // API-087.
        self::assertSame(
            RefusalKind::StateConflict,
            (new BusinessRefused(TestRefusalReason::SeatsNoLongerAvailable))->kind(),
        );
        self::assertSame(
            RefusalKind::RuleDeclined,
            (new BusinessRefused(TestRefusalReason::NotEntitled))->kind(),
        );
    }

    #[Test]
    public function a_domain_refusal_converts_to_the_business_refusal_branch_carrying_its_reason(): void
    {
        $failure = BusinessRefused::from(new BusinessRefusal(TestRefusalReason::NotEntitled));

        self::assertSame(FailureBranch::BusinessRefusal, $failure->branch());
        self::assertSame(TestRefusalReason::NotEntitled, $failure->reason());
    }

    #[Test]
    public function a_dependency_unavailability_decides_nothing_and_names_no_provider(): void
    {
        // API-089 ‡ nothing decided; API-090 the provider is not named.
        $failure = DependencyUnavailable::ofCapability('address-lookup');

        self::assertSame(FailureBranch::DependencyUnavailable, $failure->branch());
        self::assertTrue($failure->nothingWasDecided());
        self::assertSame('address-lookup', $failure->capability());
        self::assertTrue($failure->retryMayHelp());
    }

    #[Test]
    public function a_dependency_unavailability_must_name_the_affected_capability(): void
    {
        // FRD-FR-257: the actor is told what is unavailable.
        $this->expectException(InvalidArgumentException::class);

        DependencyUnavailable::ofCapability('   ');
    }

    #[Test]
    public function an_internal_fault_carries_a_correlation_identity_and_nothing_else(): void
    {
        // API-092 ‡ / BE-189 ‡: no stack, no query, no internal component named.
        $failure = new InternalFault(CorrelationIdentity::fromString('cor-1'));

        self::assertSame(FailureBranch::InternalFault, $failure->branch());
        self::assertSame('cor-1', $failure->correlationIdentity()->toString());

        $exposed = array_filter(
            get_class_methods($failure),
            static fn (string $method): bool => ! in_array($method, ['__construct', 'branch', 'correlationIdentity'], true),
        );

        self::assertSame([], array_values($exposed), 'An internal fault must expose nothing but its correlation identity.');
    }

    #[Test]
    public function each_failure_reports_a_distinct_branch(): void
    {
        // API-071 ‡: every failure is exactly one of four branches.
        $branches = [
            InvalidRequest::forField('f', 'i', 't')->branch(),
            (new BusinessRefused(TestRefusalReason::NotEntitled))->branch(),
            DependencyUnavailable::ofCapability('c')->branch(),
            (new InternalFault(CorrelationIdentity::fromString('cor-2')))->branch(),
        ];

        self::assertCount(4, array_unique($branches, SORT_REGULAR));
        self::assertCount(4, FailureBranch::cases());
    }
}
