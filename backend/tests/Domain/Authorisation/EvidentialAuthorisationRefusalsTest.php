<?php

declare(strict_types=1);

namespace Tests\Domain\Authorisation;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\AuthorisationRefusalCause;
use Cmp\Application\Shared\Authorisation\AuthorisationRefused;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Evidence\EvidenceNotRecorded;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Infrastructure\Authorisation\EvidentialAuthorisationRefusals;
use Psr\Log\NullLogger;
use Tests\Domain\DomainTestCase;
use Tests\Domain\Shared\Doubles\CollectedEvidence;
use Tests\Domain\Shared\Doubles\FixedClock;

/**
 * `SEC-057` ‡ — **every** refused authorisation is recorded.
 *
 * Level 2 (`TC-029` ‡). What the record contains, and the property that a
 * refusal cannot be reported without one, are both decidable without a database.
 * That the record reaches `ev_evidential_records` is level 3's, in
 * `AuthorisationRefusalRecordingTest`.
 */
final class EvidentialAuthorisationRefusalsTest extends DomainTestCase
{
    private const AT = '2026-08-20T11:45:00Z';

    public function test_a_refusal_is_recorded_as_evidence(): void
    {
        // SEC-057 ‡ / BE-183 / API-088 ‡ / ARCH-135 / NFR-060.
        $evidence = new CollectedEvidence;

        $this->recorder($evidence)->record(
            Operation::named('test.operation'),
            Actor::holding(ActorReference::fromString('actor-1'), []),
            AuthorisationRefusalCause::NotAParty,
        );

        $record = $evidence->only();
        self::assertSame(EvidentialAuthorisationRefusals::ACTION, $record->action());
        self::assertSame('test.operation', $record->subject());
        self::assertSame('actor-1', $record->actor()->toString());
        self::assertSame(EvidentialOutcome::Refused, $record->outcome());
        self::assertSame('2026-08-20 11:45:00.000000', $record->occurredAt()->toDatabaseString());
    }

    public function test_every_cause_produces_a_record_that_says_why(): void
    {
        // SEC-057 ‡ says "every", so every case is exercised rather than one.
        // BE-114 ‡ requires the reason, and BE-182 is why there is an internal
        // distinction to record at all — SEC-069 ‡ withholds it from the caller,
        // not from the log.
        $evidence = new CollectedEvidence;
        $recorder = $this->recorder($evidence);

        foreach (AuthorisationRefusalCause::cases() as $cause) {
            $recorder->record(
                Operation::named('test.operation'),
                Actor::holding(ActorReference::fromString('actor-1'), []),
                $cause,
            );
        }

        self::assertCount(count(AuthorisationRefusalCause::cases()), $evidence->records);

        foreach (AuthorisationRefusalCause::cases() as $index => $cause) {
            self::assertSame($cause->describe(), $evidence->records[$index]->reason());
        }
    }

    public function test_a_refusal_cannot_be_reported_without_its_record(): void
    {
        // Negative test for SEC-057 ‡ and FRD-FR-248 ‡. Authoriser records before
        // it raises; if the record cannot be written the caller receives the
        // write failure, never the refusal. The operation is still not permitted
        // — what is prevented is reporting a refusal the platform failed to
        // evidence.
        $evidence = new CollectedEvidence;
        $evidence->failEveryWrite();

        $authoriser = new Authoriser(AuthorisationPolicy::of([]), $this->recorder($evidence));

        try {
            $authoriser->authorise(
                Operation::named('test.operation'),
                Actor::holding(ActorReference::fromString('actor-1'), []),
            );
            self::fail('SEC-055 ‡: an operation with no stated rule must be refused.');
        } catch (AuthorisationRefused) {
            self::fail('FRD-FR-248 ‡: a refusal whose record could not be written must not be reported as a refusal.');
        } catch (EvidenceNotRecorded) {
            self::assertSame([], $evidence->records);
        }
    }

    public function test_asking_whether_an_operation_is_permitted_does_not_swallow_the_write_failure(): void
    {
        // Authoriser::permits() catches AuthorisationRefused so it can answer
        // false. It must not catch this: an unrecordable refusal answered as a
        // plain "no" is exactly the silent, unevidenced outcome FRD-FR-248 ‡
        // exists to prevent.
        $evidence = new CollectedEvidence;
        $evidence->failEveryWrite();

        $authoriser = new Authoriser(AuthorisationPolicy::of([]), $this->recorder($evidence));

        $this->expectException(EvidenceNotRecorded::class);

        $authoriser->permits(
            Operation::named('test.operation'),
            Actor::holding(ActorReference::fromString('actor-1'), []),
        );
    }

    public function test_the_record_carries_nothing_be_201_forbids(): void
    {
        // BE-201 ‡: no payment credential, no precise location, no contact
        // detail. Satisfied by construction — Evidence has six fields and no
        // free-form one — so what is asserted is that the four the recorder
        // chooses are an operation name, an opaque reference, an outcome and a
        // fixed cause description.
        $evidence = new CollectedEvidence;

        $this->recorder($evidence)->record(
            Operation::named('test.operation'),
            Actor::holding(ActorReference::fromString('actor-1'), []),
            AuthorisationRefusalCause::CapabilityNotHeld,
        );

        $record = $evidence->only();
        self::assertContains(
            $record->reason(),
            array_map(
                static fn (AuthorisationRefusalCause $cause): string => $cause->describe(),
                AuthorisationRefusalCause::cases(),
            ),
        );
        self::assertSame('actor-1', $record->actor()->toString());
    }

    private function recorder(CollectedEvidence $evidence): EvidentialAuthorisationRefusals
    {
        // BE-202 keeps the operational log distinct; it is not what this test is
        // about, so it goes nowhere.
        return new EvidentialAuthorisationRefusals($evidence, FixedClock::at(self::AT), new NullLogger);
    }
}
