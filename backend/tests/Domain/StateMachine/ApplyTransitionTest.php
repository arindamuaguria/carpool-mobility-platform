<?php

declare(strict_types=1);

namespace Tests\Domain\StateMachine;

use Cmp\Application\Shared\Evidence\EvidenceNotRecorded;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\StateMachine\ApplyTransition;
use Cmp\Domain\Shared\StateMachine\StateMachine;
use Cmp\Domain\Shared\StateMachine\StateMachineRefusal;
use Cmp\Domain\Shared\StateMachine\StateModel;
use Cmp\Domain\Shared\StateMachine\StateTransition;
use Cmp\Domain\Shared\StateMachine\TransitionRefused;
use Tests\Domain\DomainTestCase;
use Tests\Domain\Shared\Doubles\CollectedEvidence;
use Tests\Domain\Shared\Doubles\FixedClock;
use Tests\Domain\StateMachine\Doubles\AlwaysRefusingInvariant;

/**
 * `BE-178` — a transition is evidenced with its trigger and actor.
 *
 * Level 2 (`TC-029` ‡). What the record contains is decidable without a
 * database; that it reaches `ev_evidential_records` and chains is level 3's.
 *
 * The model here is a **test model**, for the reason `StateMachineTest` gives:
 * `BADR-13` refused to hard-code the six undecided ones, and seeding a real one
 * in a test would be inventing the same business policy through a side door.
 */
final class ApplyTransitionTest extends DomainTestCase
{
    private const AT = '2026-08-20T11:45:00Z';

    public function test_a_transition_is_evidenced_with_its_trigger_and_actor(): void
    {
        // BE-178, the whole statement.
        $evidence = new CollectedEvidence;

        $reached = $this->applier($evidence)->apply(
            $this->aModel(),
            'drafted',
            'publish',
            ActorReference::fromString('actor-1'),
            'ride-external-1',
        );

        self::assertSame('published', $reached);

        $record = $evidence->only();
        self::assertSame('test.model.publish', $record->action());
        self::assertSame('actor-1', $record->actor()->toString());
        self::assertSame('ride-external-1', $record->subject());
        self::assertSame(EvidentialOutcome::Succeeded, $record->outcome());
        self::assertSame('2026-08-20 11:45:00.000000', $record->occurredAt()->toDatabaseString());
    }

    public function test_the_action_names_the_model_as_well_as_the_trigger(): void
    {
        // "confirm" means different things to different aggregates, and a log
        // that could not tell them apart would be a poor one.
        self::assertSame('test.model.publish', ApplyTransition::actionFor($this->aModel(), 'publish'));
    }

    public function test_a_successful_transition_carries_no_reason(): void
    {
        // DB-109 ‡ deliberately omits reason from the NOT NULL list: a transition
        // that happened has none to give.
        $evidence = new CollectedEvidence;

        $this->applier($evidence)->apply(
            $this->aModel(),
            'drafted',
            'publish',
            ActorReference::fromString('actor-1'),
            'ride-external-1',
        );

        self::assertNull($evidence->only()->reason());
    }

    public function test_a_refused_transition_is_evidenced_with_its_refusal_reason(): void
    {
        // BE-114 ‡: refused operations are evidenced with their refusal reason.
        // A state machine that logged only its successes would hide the cases
        // worth reading.
        $evidence = new CollectedEvidence;

        try {
            $this->applier($evidence)->apply(
                $this->aModel(),
                'drafted',
                'complete',
                ActorReference::fromString('actor-1'),
                'ride-external-1',
            );
            self::fail('BE-176 ‡: an undeclared transition must be refused.');
        } catch (TransitionRefused $refused) {
            self::assertSame(StateMachineRefusal::TransitionNotDeclared, $refused->refusal());
        }

        $record = $evidence->only();
        self::assertSame(EvidentialOutcome::Refused, $record->outcome());
        self::assertSame(StateMachineRefusal::TransitionNotDeclared->name, $record->reason());
        self::assertSame('test.model.complete', $record->action());
    }

    public function test_the_reason_is_the_bounded_case_and_not_the_prose_detail(): void
    {
        // TransitionRefused::detail() is written for a caller's error path, where
        // API-086 ‡ and API-094 ‡ decide what may be said, and its length is
        // unbounded — an invariant's own description is part of it. The evidential
        // reason is the case, which is a fixed value.
        $evidence = new CollectedEvidence;
        $applier = new ApplyTransition(
            new StateMachine([new AlwaysRefusingInvariant('the test double refuses everything')]),
            $evidence,
            FixedClock::at(self::AT),
        );

        try {
            $applier->apply(
                $this->aModel(),
                'drafted',
                'publish',
                ActorReference::fromString('actor-1'),
                'ride-external-1',
            );
            self::fail('BE-177 ‡: an invariant must be able to refuse a declared transition.');
        } catch (TransitionRefused $refused) {
            self::assertNotSame($refused->detail(), $evidence->only()->reason());
        }

        self::assertSame(StateMachineRefusal::InvariantForbidsIt->name, $evidence->only()->reason());
    }

    public function test_a_transition_whose_record_cannot_be_written_is_not_reported_as_having_happened(): void
    {
        // Negative test for FRD-FR-248 ‡: "the system shall not report an action
        // as complete where its auditable record cannot be written." The caller
        // receives the write failure, not a destination state.
        $evidence = new CollectedEvidence;
        $evidence->failEveryWrite();

        $this->expectException(EvidenceNotRecorded::class);

        $this->applier($evidence)->apply(
            $this->aModel(),
            'drafted',
            'publish',
            ActorReference::fromString('actor-1'),
            'ride-external-1',
        );
    }

    public function test_the_engine_still_refuses_what_the_model_does_not_declare(): void
    {
        // BE-176 ‡ is the engine's, and wrapping it must not soften it. The
        // refusal is re-raised unchanged, so nothing downstream can tell that a
        // record was written in between.
        $this->expectException(TransitionRefused::class);

        $this->applier(new CollectedEvidence)->apply(
            $this->aModel(),
            'invented',
            'publish',
            ActorReference::fromString('actor-1'),
            'ride-external-1',
        );
    }

    private function applier(CollectedEvidence $evidence): ApplyTransition
    {
        return new ApplyTransition(new StateMachine, $evidence, FixedClock::at(self::AT));
    }

    private function aModel(): StateModel
    {
        return StateModel::of(
            'test.model',
            ['drafted', 'published'],
            [StateTransition::of('drafted', 'publish', 'published')],
        );
    }
}
