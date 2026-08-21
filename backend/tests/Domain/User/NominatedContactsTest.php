<?php

declare(strict_types=1);

namespace Tests\Domain\User;

use Cmp\Domain\Shared\Refusal\BusinessRefusal;
use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\User\ContactLabel;
use Cmp\Domain\User\EmergencyContact;
use Cmp\Domain\User\EmergencyContactReference;
use Cmp\Domain\User\EmergencyContactRefusal;
use Cmp\Domain\User\NominatedContacts;
use Cmp\Domain\User\PhoneNumber;
use Cmp\Domain\User\UserReference;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `UC-048` — the set, and what it will not become.
 *
 * **Level 2** (`TC-025`, `TC-029` ‡): no database, no framework, no network.
 * Everything here is decidable from the rule alone, so `TC-033` puts it at this
 * level rather than a later one.
 */
final class NominatedContactsTest extends TestCase
{
    private const USER = 'aaaabbbbccccddddeeeeffff00001111';

    public function test_a_user_may_nominate_more_than_one(): void
    {
        // UC-048 A1, and FRD-FR-181's "one or more".
        $contacts = NominatedContacts::noneFor($this->user())
            ->with($this->contact('1', '+910000000001'))
            ->with($this->contact('2', '+910000000002'));

        self::assertSame(2, $contacts->count());
        self::assertFalse($contacts->isEmpty());
    }

    public function test_no_bound_is_imposed_on_how_many(): void
    {
        // FRD-FR-181 permits "one or more" and no document bounds the set.
        // Inventing a maximum here would be a decision about how many people a
        // user may ask for help, taken in a value object. NFR-057 / API-127 make
        // request-rate limiting the platform's stated answer to volume, and
        // CC-044 records the absence with what would decide it.
        $contacts = NominatedContacts::noneFor($this->user());

        for ($i = 0; $i < 50; $i++) {
            // TC-203 ‡: the literal is itself a synthetic number, not a prefix
            // that only becomes one once concatenated — TC-206's detector reads
            // what is written in the file, and it is right to.
            $contacts = $contacts->with($this->contact((string) $i, '+910000001'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)));
        }

        self::assertSame(50, $contacts->count());
    }

    public function test_the_same_number_twice_is_refused_with_a_reason(): void
    {
        // UC-048 A1: "the platform records the set." A set holds a number once,
        // and FRD-FR-183 requires the platform to state why rather than absorb
        // the second nomination silently.
        $contacts = NominatedContacts::noneFor($this->user())->with($this->contact('1', '+910000000001'));

        try {
            $contacts->with($this->contact('2', '+910000000001'));
            self::fail('UC-048 A1: a number appears in the set once.');
        } catch (BusinessRefusal $refusal) {
            self::assertSame(EmergencyContactRefusal::AlreadyNominated, $refusal->reason());
            self::assertSame(RefusalKind::StateConflict, $refusal->kind());
        }
    }

    public function test_amending_is_not_a_route_around_the_set_rule(): void
    {
        // The collision the set rule exists to prevent, reached the other way.
        $contacts = NominatedContacts::noneFor($this->user())
            ->with($this->contact('1', '+910000000001'))
            ->with($this->contact('2', '+910000000002'));

        $this->expectException(BusinessRefusal::class);

        $contacts->amending(
            $this->reference('2'),
            PhoneNumber::fromString('+910000000001'),
            null,
        );
    }

    public function test_amending_keeps_the_nomination_and_replaces_the_details(): void
    {
        // FRD-FR-182. The reference is what makes an amendment an amendment
        // rather than a removal followed by a nomination.
        $amended = NominatedContacts::noneFor($this->user())
            ->with($this->contact('1', '+910000000001', 'Sister'))
            ->amending($this->reference('1'), PhoneNumber::fromString('+910000000009'), ContactLabel::fromString('Brother'));

        $contact = $amended->referenced($this->reference('1'));

        self::assertNotNull($contact);
        self::assertSame('+910000000009', $contact->number()->toString());
        self::assertSame('Brother', $contact->label()?->toString());
        self::assertSame(1, $amended->count());
    }

    public function test_a_reference_the_set_does_not_hold_is_refused_identically_whether_it_is_absent_or_anothers(): void
    {
        // SEC-069 ‡ / API-094 ‡, and the reason they are satisfied by
        // construction: the set is one user's, so a reference belonging to
        // somebody else and one belonging to nobody take the same path.
        $contacts = NominatedContacts::noneFor($this->user())->with($this->contact('1', '+910000000001'));

        $absent = $this->refusalFrom(fn () => $contacts->without($this->reference('9')));
        $anothers = $this->refusalFrom(fn () => $contacts->without($this->reference('8')));

        self::assertSame(EmergencyContactRefusal::NotNominated, $absent->reason());
        self::assertSame($absent->reason(), $anothers->reason());
        self::assertSame($absent->reason()->defaultText(), $anothers->reason()->defaultText());
    }

    public function test_removing_leaves_the_rest_of_the_set(): void
    {
        // FRD-FR-182.
        $contacts = NominatedContacts::noneFor($this->user())
            ->with($this->contact('1', '+910000000001'))
            ->with($this->contact('2', '+910000000002'))
            ->without($this->reference('1'));

        self::assertSame(1, $contacts->count());
        self::assertNull($contacts->referenced($this->reference('1')));
        self::assertNotNull($contacts->referenced($this->reference('2')));
    }

    public function test_every_operation_leaves_the_original_set_unchanged(): void
    {
        // FRD-FR-024's principle, applied structurally: a rejected write leaves
        // what is stored unchanged. It cannot do otherwise if the type never
        // mutates, and the application service only saves what it received back.
        $original = NominatedContacts::noneFor($this->user())->with($this->contact('1', '+910000000001'));

        $original->with($this->contact('2', '+910000000002'));
        $original->without($this->reference('1'));
        $original->amending($this->reference('1'), PhoneNumber::fromString('+910000000003'), null);

        self::assertSame(1, $original->count());
        self::assertSame('+910000000001', $original->all()[0]->number()->toString());
    }

    /**
     * `FRD-FR-195` ‡ and `FRD-GAP-020`, made structural.
     *
     * `BE-195` ‡ would attempt notification *"through the highest-priority
     * family"*, and every part of whether and when a contact is informed is
     * blocked on `BAD-DEC-011`. `UC-048` accepts the platform holding contacts
     * *"only because **nothing is sent to them**"*.
     *
     * So the types carry no method that reaches anybody and no ordering that
     * would be the first half of one. This asserts the surface, which is the
     * only form of that prohibition a build can check: a future change adding
     * `notify()` or `highestPriority()` fails here, before it fails a review.
     */
    public function test_nothing_in_the_domain_offers_a_way_to_reach_a_contact(): void
    {
        $forbidden = ['notify', 'send', 'alert', 'inform', 'dispatch', 'contact', 'priority', 'escalate'];

        foreach ([NominatedContacts::class, EmergencyContact::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                foreach ($forbidden as $verb) {
                    self::assertStringNotContainsStringIgnoringCase(
                        $verb,
                        $method->getName(),
                        $class.'::'.$method->getName().'() — FRD-GAP-020 blocks informing a contact on '
                        .'BAD-DEC-011, and UC-048 holds contacts only because nothing is sent to them.',
                    );
                }
            }
        }
    }

    /**
     * @param  callable(): mixed  $act
     */
    private function refusalFrom(callable $act): BusinessRefusal
    {
        try {
            $act();
        } catch (BusinessRefusal $refusal) {
            return $refusal;
        }

        self::fail('The refusal this test is about did not happen.');
    }

    private function contact(string $seed, string $number, ?string $label = null): EmergencyContact
    {
        return EmergencyContact::of(
            $this->reference($seed),
            PhoneNumber::fromString($number),
            $label === null ? null : ContactLabel::fromString($label),
        );
    }

    private function reference(string $seed): EmergencyContactReference
    {
        return EmergencyContactReference::fromString(substr(hash('sha256', 'contact-'.$seed), 0, 32));
    }

    private function user(): UserReference
    {
        return UserReference::fromString(self::USER);
    }
}
