<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\StateMachine;

use Cmp\Domain\Shared\Refusal\BusinessRefusal;

/**
 * A lifecycle transition was refused.
 *
 * A **business refusal** (`BADR-17`), not a fault: the platform decided, and
 * `BE-186` ‡ forbids representing that decision as an internal fault. Retrying
 * will not change the answer while the model and the invariants stand.
 *
 * Three ways to arrive here, kept apart because they mean different things to an
 * operator reading the refusal — and collapsed to one answer for a caller, which
 * is `StateMachineRefusal`'s doing.
 */
final class TransitionRefused extends BusinessRefusal
{
    private function __construct(
        private readonly StateMachineRefusal $refusal,
        private readonly string $detail,
    ) {
        parent::__construct($refusal);
    }

    /**
     * `SRS-REQ-158`: a value that has not been configured is rejected.
     */
    public static function becauseStateIsNotDeclared(StateModel $model, string $state): self
    {
        return new self(
            StateMachineRefusal::StateNotDeclared,
            sprintf('SRS-REQ-158: "%s" is not a state that %s declares.', $state, $model->name()),
        );
    }

    /**
     * `BE-176` ‡: an undeclared transition is refused.
     */
    public static function becauseTransitionIsNotDeclared(StateModel $model, string $from, string $trigger): self
    {
        return new self(
            StateMachineRefusal::TransitionNotDeclared,
            sprintf('BE-176 ‡: %s declares no transition from "%s" on "%s".', $model->name(), $from, $trigger),
        );
    }

    /**
     * `BE-177` ‡: a permissive definition does not admit a prohibited outcome.
     */
    public static function becauseAnInvariantForbidsIt(
        StateModel $model,
        StateTransition $transition,
        TransitionInvariant $invariant,
        string $reason,
    ): self {
        return new self(
            StateMachineRefusal::InvariantForbidsIt,
            sprintf(
                'BE-177 ‡: %s declares %s, but %s forbids it: %s.',
                $model->name(),
                $transition->describe(),
                $invariant->describe(),
                $reason,
            ),
        );
    }

    public function refusal(): StateMachineRefusal
    {
        return $this->refusal;
    }

    /**
     * Why the transition was refused, for an operator or a log.
     *
     * **Not for a caller.** `API-086` ‡ and `API-094` ‡ limit what a refusal may
     * tell a caller about platform state; a caller is shown the reason identifier
     * and its presentation default, which come from {@see StateMachineRefusal}.
     */
    public function detail(): string
    {
        return $this->detail;
    }
}
