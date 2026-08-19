<?php

declare(strict_types=1);

namespace Tests\Domain\StateMachine;

use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\Shared\StateMachine\StateMachine;
use Cmp\Domain\Shared\StateMachine\StateMachineRefusal;
use Cmp\Domain\Shared\StateMachine\StateModel;
use Cmp\Domain\Shared\StateMachine\StateTransition;
use Cmp\Domain\Shared\StateMachine\TransitionRefused;
use InvalidArgumentException;
use Tests\Domain\DomainTestCase;
use Tests\Domain\StateMachine\Doubles\AlwaysRefusingInvariant;
use Tests\Domain\StateMachine\Doubles\RecordingInvariant;

/**
 * CMP-IMP-032 — the configuration-driven state machine engine.
 *
 * Level 2 (`TC-029` ‡). The engine is code and the models are configuration
 * (`BADR-13`), so what the engine does with a given model is decidable without a
 * database.
 *
 * The model used here is a **test model**. Seeding a real one would be inventing
 * business policy, which is what `BADR-13` refused to do: *"six are undecided;
 * this is inventing business policy."*
 */
final class StateMachineTest extends DomainTestCase
{
    public function test_a_declared_transition_is_applied(): void
    {
        $reached = (new StateMachine)->apply($this->aModel(), 'drafted', 'publish');

        self::assertSame('published', $reached);
    }

    public function test_an_undeclared_transition_is_refused(): void
    {
        // Negative test for BE-176 ‡ / SRS-REQ-158. BADR-13 calls this "natural
        // engine behaviour" rather than a check bolted on.
        try {
            (new StateMachine)->apply($this->aModel(), 'drafted', 'complete');
            self::fail('BE-176 ‡: an undeclared transition must be refused.');
        } catch (TransitionRefused $refused) {
            self::assertSame(StateMachineRefusal::TransitionNotDeclared, $refused->refusal());
            self::assertStringContainsString('BE-176', $refused->detail());
        }
    }

    public function test_a_state_the_model_does_not_declare_is_refused(): void
    {
        // Negative test for SRS-REQ-158: a value that has not been configured is
        // rejected.
        try {
            (new StateMachine)->apply($this->aModel(), 'invented', 'publish');
            self::fail('SRS-REQ-158: an unconfigured state must be rejected.');
        } catch (TransitionRefused $refused) {
            self::assertSame(StateMachineRefusal::StateNotDeclared, $refused->refusal());
        }
    }

    public function test_a_coded_invariant_refuses_a_transition_the_model_permits(): void
    {
        // BE-177 ‡ / ARCH-038: "a permissive definition shall not admit a
        // prohibited outcome." This is the property the whole design turns on.
        $machine = new StateMachine([new AlwaysRefusingInvariant('the subject is not ready')]);

        try {
            $machine->apply($this->aModel(), 'drafted', 'publish');
            self::fail('BE-177 ‡: a coded invariant must hold irrespective of the declared model.');
        } catch (TransitionRefused $refused) {
            self::assertSame(StateMachineRefusal::InvariantForbidsIt, $refused->refusal());
            self::assertStringContainsString('the subject is not ready', $refused->detail());
        }
    }

    public function test_an_invariant_cannot_permit_a_transition_the_model_does_not_declare(): void
    {
        // The asymmetry is the safety property: an invariant can only refuse.
        // There is no method on the contract that permits anything, so a
        // permissive invariant cannot exist to be written.
        $machine = new StateMachine([new RecordingInvariant]);

        try {
            $machine->apply($this->aModel(), 'drafted', 'complete');
            self::fail('An undeclared transition must stay refused.');
        } catch (TransitionRefused $refused) {
            self::assertSame(StateMachineRefusal::TransitionNotDeclared, $refused->refusal());
        }
    }

    public function test_invariants_run_after_the_model_and_not_before(): void
    {
        // An invariant asked about an undeclared transition would be asked to
        // reason about something that cannot happen.
        $invariant = new RecordingInvariant;
        $machine = new StateMachine([$invariant]);

        try {
            $machine->apply($this->aModel(), 'drafted', 'complete');
        } catch (TransitionRefused) {
            // Expected.
        }

        self::assertSame([], $invariant->seen());

        $machine->apply($this->aModel(), 'drafted', 'publish');

        self::assertSame(['drafted --publish--> published'], $invariant->seen());
    }

    public function test_every_invariant_is_consulted_until_one_refuses(): void
    {
        $first = new RecordingInvariant;
        $second = new RecordingInvariant;

        (new StateMachine([$first, $second]))->apply($this->aModel(), 'drafted', 'publish');

        self::assertCount(1, $first->seen());
        self::assertCount(1, $second->seen());
    }

    public function test_the_invariants_in_force_can_be_read(): void
    {
        // BE-177 ‡ is only checkable if what holds irrespective of the model can
        // be listed.
        $machine = new StateMachine([new AlwaysRefusingInvariant('r')]);

        self::assertSame(['a test invariant that always refuses'], $machine->invariants());
    }

    public function test_a_refusal_is_a_business_refusal_and_not_a_fault(): void
    {
        // BADR-17 / BE-186 ‡: the platform decided; that decision is never
        // represented as an internal fault.
        $refused = TransitionRefused::becauseTransitionIsNotDeclared($this->aModel(), 'drafted', 'complete');

        self::assertSame('lifecycle.transition_not_permitted', $refused->reason()->identifier());
        self::assertSame(RefusalKind::StateConflict, $refused->kind());
        self::assertNotSame('', $refused->reason()->defaultText());
    }

    public function test_a_caller_cannot_tell_an_undeclared_transition_from_a_forbidden_one(): void
    {
        // API-094 ‡ / API-086 ‡: a caller who could tell them apart could map the
        // platform's lifecycle, and the rules behind it, by probing.
        self::assertSame(
            StateMachineRefusal::TransitionNotDeclared->identifier(),
            StateMachineRefusal::InvariantForbidsIt->identifier(),
        );
        self::assertSame(
            StateMachineRefusal::TransitionNotDeclared->defaultText(),
            StateMachineRefusal::InvariantForbidsIt->defaultText(),
        );
    }

    public function test_permits_agrees_with_apply(): void
    {
        $machine = new StateMachine([new AlwaysRefusingInvariant('r')]);

        self::assertFalse($machine->permits($this->aModel(), 'drafted', 'publish'));
        self::assertTrue((new StateMachine)->permits($this->aModel(), 'drafted', 'publish'));
    }

    public function test_a_model_declaring_a_transition_to_an_unknown_state_is_refused(): void
    {
        // A definition that could name a state outside its own permitted set
        // would let SRS-REQ-158 be bypassed by the definition itself.
        $this->expectException(InvalidArgumentException::class);

        StateModel::of('test.model', ['drafted'], [StateTransition::of('drafted', 'publish', 'published')]);
    }

    public function test_a_model_with_no_states_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StateModel::of('test.model', [], []);
    }

    private function aModel(): StateModel
    {
        return StateModel::of(
            'test.model',
            ['drafted', 'published', 'withdrawn'],
            [
                StateTransition::of('drafted', 'publish', 'published'),
                StateTransition::of('published', 'withdraw', 'withdrawn'),
            ],
        );
    }
}
