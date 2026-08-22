<?php

declare(strict_types=1);

namespace Tests\Domain\Safety;

use Cmp\Domain\Safety\ContextElement;
use Cmp\Domain\Safety\ContextStanding;
use Cmp\Domain\Safety\IncidentContext;
use Cmp\Domain\Safety\IncidentOutcome;
use Cmp\Domain\Safety\IncidentReference;
use Cmp\Domain\Safety\SafetyIncident;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\UserReference;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `UC-051` — the aggregate, and the four rules it cannot be talked out of.
 *
 * **Level 2** (`TC-025`, `TC-029` ‡): no database, no framework, no network.
 */
final class SafetyIncidentTest extends TestCase
{
    private const RAISER = 'aaaa1111bbbb2222cccc3333dddd4444';

    private const INCIDENT = '1111aaaa2222bbbb3333cccc4444dddd';

    public function test_a_signal_always_becomes_a_record(): void
    {
        // FRD-FR-185 ‡ / FRD-FR-188 ‡ / BD-04. There is no argument that
        // produces a refusal, because there is no state in which the platform is
        // permitted to lose a signal.
        $incident = $this->raised();

        self::assertSame(self::INCIDENT, $incident->reference()->toString());
        self::assertSame(self::RAISER, $incident->raisedBy()->toString());
        self::assertFalse($incident->isRouted());
        self::assertFalse($incident->isClosed());
    }

    public function test_an_incident_records_what_it_does_not_know(): void
    {
        // FRD-FR-187 ‡: recorded even where part of its context is unavailable,
        // with the missing context marked. Today that is all four elements, each
        // for a reason recorded in the migration.
        $incident = $this->raised();

        foreach (ContextElement::cases() as $element) {
            self::assertSame(
                ContextStanding::Unavailable,
                $incident->context()->standingOf($element),
                $element->value.' has no standing, so DB-078 ‡ cannot answer for it.',
            );
        }

        self::assertTrue($incident->context()->isWhollyUnavailable());
    }

    public function test_a_context_missing_an_element_is_refused(): void
    {
        // DB-078 ‡, from the other side. Defaulting an absent key to
        // "unavailable" would make an element nobody considered look like one
        // the platform tried and failed to obtain — which is precisely the
        // difference the requirement exists to preserve.
        $this->expectException(InvalidArgumentException::class);

        IncidentContext::of([
            ContextElement::Trip->value => ContextStanding::AbsentInFact,
            ContextElement::Vehicle->value => ContextStanding::Unavailable,
            // Location omitted, and co-travellers with it.
        ]);
    }

    public function test_absent_in_fact_is_not_the_same_as_unavailable(): void
    {
        // UC-051 E3 — a signal raised outside an active trip has no trip, and an
        // operator must be able to tell "they were not travelling" from "we do
        // not know".
        $context = IncidentContext::of([
            ContextElement::Trip->value => ContextStanding::AbsentInFact,
            ContextElement::Vehicle->value => ContextStanding::Unavailable,
            ContextElement::CoTravellers->value => ContextStanding::Unavailable,
            ContextElement::Location->value => ContextStanding::Unavailable,
        ]);

        self::assertNotSame(
            $context->standingOf(ContextElement::Trip),
            $context->standingOf(ContextElement::Vehicle),
        );

        self::assertTrue($context->standingOf(ContextElement::Trip)->isKnown());
        self::assertFalse($context->standingOf(ContextElement::Vehicle)->isKnown());
        self::assertFalse($context->isWhollyUnavailable());
    }

    public function test_routing_keeps_the_first_instant(): void
    {
        // BE-135 ‡: a queue redelivers, and a redelivery must not move the time
        // at which the signal reached the queue — that figure is what
        // FRD-FR-190 ‡'s retention is measured against.
        $incident = $this->raised();

        $incident->routed(Instant::fromString('2026-08-22 10:00:00.000000'));
        $incident->routed(Instant::fromString('2026-08-22 11:00:00.000000'));

        self::assertTrue($incident->isRouted());
        self::assertSame('2026-08-22 10:00:00.000000', $incident->routedAt()?->toDatabaseString());
    }

    /**
     * `BE-029` ‡ / `API-171` ‡ — the negative test `FR-04` requires.
     *
     * *"`SafetyIncident` shall not close without a recorded outcome."* The rule
     * is not enforced by a check that could be forgotten: there is **no method
     * that closes without one**, so the violating call does not compile as a
     * call at all.
     */
    public function test_there_is_no_way_to_close_an_incident_without_an_outcome(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(SafetyIncident::class))->getMethods(),
        );

        self::assertNotContains('close', $methods, 'BE-029 ‡: closure takes an outcome, so there is no close().');

        // And the one that does close requires one — a parameter, not a
        // nullable, so an outcome cannot be omitted.
        $closure = (new ReflectionClass(SafetyIncident::class))->getMethod('closedWith');
        $outcome = $closure->getParameters()[0];

        self::assertSame('outcome', $outcome->getName());
        self::assertFalse($outcome->allowsNull(), 'BE-029 ‡: a null outcome is not a recorded one.');
        self::assertFalse($outcome->isOptional(), 'BE-029 ‡: an omitted outcome is not a recorded one.');
    }

    public function test_a_blank_outcome_is_not_a_recorded_one(): void
    {
        // UC-051's minimal guarantee: "none is closed without a record." A
        // whitespace outcome would satisfy a not-null check and record nothing.
        $this->expectException(InvalidArgumentException::class);

        IncidentOutcome::fromString('   ');
    }

    public function test_a_stored_incident_closed_without_an_outcome_is_refused_on_the_way_in(): void
    {
        // BE-029 ‡ again, at the boundary. A row in this state means something
        // bypassed the aggregate; reading it back silently would make the record
        // look lawful, which is worse than failing.
        $this->expectException(LogicException::class);

        SafetyIncident::reconstitute(
            IncidentReference::fromString(self::INCIDENT),
            UserReference::fromString(self::RAISER),
            Instant::fromString('2026-08-22 09:00:00.000000'),
            IncidentContext::unavailable(),
            null,
            Instant::fromString('2026-08-22 12:00:00.000000'),
            null,
        );
    }

    /**
     * `FRD-FR-195` ‡ / `GAP-004` / `BRD-REQ-114`, made structural.
     *
     * CMP-DOC-10 §12.4: *"No operation is specified. The incident resource
     * exists so that the absence of a dispatch path is **visible rather than
     * assumed**."* An aggregate that grew a `notify()` would make it assumed
     * again, and `NFR-137` forbids implying a protection the platform does not
     * provide.
     */
    public function test_nothing_on_the_aggregate_reaches_anybody(): void
    {
        $forbidden = ['notify', 'alert', 'dispatch', 'escalate', 'inform', 'respond', 'contact', 'summon'];

        foreach ((new ReflectionClass(SafetyIncident::class))->getMethods() as $method) {
            foreach ($forbidden as $verb) {
                self::assertStringNotContainsStringIgnoringCase(
                    $verb,
                    $method->getName(),
                    SafetyIncident::class.'::'.$method->getName().'() — BAD-DEC-011 is open, no response '
                    .'capability is staffed, and CMP-DOC-10 §12.4 keeps the absence of a dispatch path visible.',
                );
            }
        }
    }

    private function raised(): SafetyIncident
    {
        return SafetyIncident::raise(
            IncidentReference::fromString(self::INCIDENT),
            UserReference::fromString(self::RAISER),
            Instant::fromString('2026-08-22 09:00:00.000000'),
            IncidentContext::unavailable(),
        );
    }
}
