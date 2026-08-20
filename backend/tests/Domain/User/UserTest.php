<?php

declare(strict_types=1);

namespace Tests\Domain\User;

use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\AccountState;
use Cmp\Domain\User\Event\PhoneNumberVerified;
use Cmp\Domain\User\Event\UserRegistered;
use Cmp\Domain\User\PhoneNumber;
use Cmp\Domain\User\User;
use Cmp\Domain\User\UserReference;
use Cmp\Domain\User\VerificationStanding;
use InvalidArgumentException;
use ReflectionClass;
use Tests\Domain\DomainTestCase;

/**
 * `CMP-IMP-052` — the User aggregate.
 *
 * Level 2 (`TC-029` ‡): no database, no framework, no network. Everything the
 * aggregate decides is decidable here, which is `TC-033`'s test for where an
 * obligation belongs.
 */
final class UserTest extends DomainTestCase
{
    private const REFERENCE = '0123456789abcdef0123456789abcdef';

    private const AT = '2026-08-20T12:00:00Z';

    public function test_a_new_account_is_unverified_and_active(): void
    {
        // FRD-FR-006: created "in an unverified state that does not permit
        // participation". ACTIVE because it is the only state BAD-RULE-010 gives
        // that permits use at all — an account created SUSPENDED could never be
        // reached, since no transition out of SUSPENDED is defined.
        $user = $this->registered();

        self::assertSame(VerificationStanding::Unverified, $user->verificationStanding());
        self::assertSame(AccountState::Active, $user->accountState());
        self::assertFalse($user->permitsParticipation());
    }

    public function test_registration_records_an_event_rather_than_dispatching_one(): void
    {
        // BE-022. BE-058 dispatches after the producing transaction commits, so
        // nothing a listener does is part of the registration.
        $events = $this->registered()->releaseRecordedEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(UserRegistered::class, $events[0]);
        self::assertSame('user.registered', $events[0]->eventName());
    }

    public function test_the_registration_event_carries_a_reference_and_not_a_number(): void
    {
        // BE-201 ‡: a contact detail stays out of a log, and an event reaches
        // listeners, jobs and eventually a record that outlives the account.
        $events = $this->registered()->releaseRecordedEvents();
        self::assertInstanceOf(UserRegistered::class, $events[0]);

        $encoded = json_encode(get_object_vars($events[0]), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('+910000000001', $encoded);
        self::assertSame(self::REFERENCE, $events[0]->user()->toString());
    }

    public function test_a_demonstrated_number_verifies_the_account(): void
    {
        // FRD-FR-008: "usable only after control of its phone number has been
        // demonstrated."
        $user = $this->registered();
        $user->releaseRecordedEvents();

        $user->phoneNumberDemonstrated(Instant::fromString(self::AT));

        self::assertSame(VerificationStanding::Verified, $user->verificationStanding());
        self::assertTrue($user->permitsParticipation());

        $events = $user->releaseRecordedEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(PhoneNumberVerified::class, $events[0]);
    }

    public function test_demonstrating_a_number_twice_changes_nothing_and_records_nothing(): void
    {
        // API-062 ‡: a repeated request with the same key returns the original
        // outcome and produces no second effect. An aggregate that raised on the
        // second call would make that impossible to honour, and one that recorded
        // a second event would give a listener something that did not happen.
        $user = $this->registered();
        $user->phoneNumberDemonstrated(Instant::fromString(self::AT));
        $user->releaseRecordedEvents();

        $user->phoneNumberDemonstrated(Instant::fromString('2026-08-21T12:00:00Z'));

        self::assertSame(VerificationStanding::Verified, $user->verificationStanding());
        self::assertSame([], $user->recordedEvents());
    }

    public function test_only_an_active_account_permits_authenticated_use(): void
    {
        // SEC-051 ‡ / BAD-RULE-010, and the negative half for each of the two
        // states that prevent it.
        self::assertTrue($this->inState(AccountState::Active)->permitsAuthenticatedUse());
        self::assertFalse($this->inState(AccountState::Suspended)->permitsAuthenticatedUse());
        self::assertFalse($this->inState(AccountState::Deactivated)->permitsAuthenticatedUse());
    }

    public function test_a_verified_but_suspended_account_permits_nothing(): void
    {
        // Negative test for the conjunction. Verification and account state are
        // separate axes, and satisfying one is not satisfying both — which is
        // exactly the mistake a caller checking only the standing would make.
        $user = $this->inState(AccountState::Suspended, VerificationStanding::Verified);

        self::assertTrue($user->verificationStanding()->permitsParticipation());
        self::assertFalse($user->permitsParticipation());
    }

    public function test_the_aggregate_offers_no_way_to_change_an_account_state(): void
    {
        // BAD-RULE-010 permits a SUSPENDED or DEACTIVATED account to regain an
        // active session only "through a defined account-state transition", and
        // none is defined — FRD-GAP-024 is Critical and open on BAD-DEC-006 and
        // BAD-DEC-016. BE-012 puts an absolute rule beyond reach of override, and
        // the way to put a transition beyond reach is not to write one.
        //
        // Asserted rather than trusted, because the method that would appear here
        // is exactly the one somebody would add without reading the gap register.
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(User::class))->getMethods(),
        );

        foreach ($methods as $method) {
            foreach (['suspend', 'deactivate', 'reactivate', 'restore', 'close', 'ban', 'unsuspend'] as $transition) {
                self::assertStringNotContainsString(
                    $transition,
                    strtolower($method),
                    'BAD-RULE-010 / FRD-GAP-024: no account-state transition is defined, so none is offered.',
                );
            }
        }
    }

    public function test_the_account_state_property_cannot_be_reassigned(): void
    {
        // The other half of the same rule: a readonly property is what makes the
        // absence of a transition method structural rather than a convention.
        $property = (new ReflectionClass(User::class))->getProperty('accountState');

        self::assertTrue($property->isReadOnly());
    }

    public function test_an_account_cannot_be_built_without_going_through_a_named_constructor(): void
    {
        // BE-021: constructible into a valid state only. A public constructor
        // would be a second way in, and the one nobody validates.
        self::assertTrue((new ReflectionClass(User::class))->getConstructor()?->isPrivate());
    }

    public function test_a_phone_number_is_required_and_not_merely_expected(): void
    {
        // FRD-FR-003's "absent" half, and BAD-RULE-043 — it is the one mandatory
        // identifying detail, so an empty one is not a User at all.
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::fromString('   ');
    }

    public function test_a_phone_number_carries_no_internal_whitespace(): void
    {
        // Not a format rule: FRD-FR-004 refuses a number already registered and
        // DB-209 ‡ enforces that by a unique constraint, which cannot see through
        // two spellings of one number.
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::fromString('+91 00000 00001');
    }

    public function test_the_phone_number_format_is_not_invented(): void
    {
        // FRD-FR-003 requires a malformed detail to be rejected and NO DOCUMENT
        // STATES WHAT MALFORMED MEANS for a phone number. Imposing E.164, a
        // country prefix or a digit count here would be a business decision about
        // who may register, taken where nobody would look for it.
        //
        // This asserts the absence deliberately: each of these is accepted today,
        // and the day a format rule is decided this test is what fails and points
        // at the decision.
        foreach (['+910000000001', '00000000001', '+1-555-0100', 'x'] as $unvalidated) {
            self::assertSame($unvalidated, PhoneNumber::fromString($unvalidated)->toString());
        }
    }

    public function test_an_external_reference_encodes_nothing(): void
    {
        // DB-023 ‡: no meaning, no sequence, no timestamp. Lower-case hexadecimal
        // of a fixed length is the form that carries none of the three, and
        // anything else is refused rather than accepted and hoped about.
        self::assertSame(self::REFERENCE, UserReference::fromString(self::REFERENCE)->toString());

        foreach (['user-0000000000000000000000001', strtoupper(self::REFERENCE), 'short'] as $refused) {
            try {
                UserReference::fromString($refused);
                self::fail('DB-022 ‡ / DB-023 ‡: '.$refused.' must be refused as an external identifier.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }
    }

    private function registered(): User
    {
        return User::register(
            UserReference::fromString(self::REFERENCE),
            PhoneNumber::fromString('+910000000001'),
            Instant::fromString(self::AT),
        );
    }

    private function inState(
        AccountState $state,
        VerificationStanding $standing = VerificationStanding::Unverified,
    ): User {
        return User::reconstitute(
            UserReference::fromString(self::REFERENCE),
            PhoneNumber::fromString('+910000000001'),
            $standing,
            $state,
        );
    }
}
