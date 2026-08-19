<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\StateMachine;

/**
 * The one engine that evaluates a lifecycle transition (`BE-175`).
 *
 * `BADR-13` rejected a separate engine per aggregate — *"six variants of the same
 * mechanism"* — and rejected hard-coding the proposed models outright: *"six are
 * undecided; this is inventing business policy."* So the engine is code and the
 * models are configuration.
 *
 * A transition is permitted only if **both** hold:
 *
 * 1. **The model declares it.** `BE-176` ‡ / `SRS-REQ-158`: an undeclared
 *    transition, or a state the model does not permit, is refused. `BADR-13`
 *    notes this is *"natural engine behaviour"* rather than a check bolted on.
 * 2. **No coded invariant refuses it.** `BE-177` ‡ / `ARCH-038`: a permissive
 *    definition shall not admit a prohibited outcome. The invariants run
 *    **after** the model and can only refuse — there is no way for one to permit
 *    something the model did not declare, and no way for a model to override
 *    one.
 *
 * That ordering is the safety property. `BADR-13` accepts that a misconfigured
 * model can stall a workflow; what it does not accept is a misconfigured model
 * admitting something the domain forbids, and the asymmetry above is why it
 * cannot.
 */
final class StateMachine
{
    /** @var list<TransitionInvariant> */
    private readonly array $invariants;

    /**
     * @param  list<TransitionInvariant>  $invariants  the rules that hold whatever
     *                                                 the model says (`BE-177` ‡)
     */
    public function __construct(array $invariants = [])
    {
        $this->invariants = array_values($invariants);
    }

    /**
     * The state reached by applying a trigger.
     *
     * @param  object|null  $subject  the aggregate the transition concerns, passed
     *                                to the invariants that need it
     *
     * @throws TransitionRefused where the model does not declare the transition
     *                           (`BE-176` ‡) or an invariant refuses it
     *                           (`BE-177` ‡)
     */
    public function apply(StateModel $model, string $currentState, string $trigger, ?object $subject = null): string
    {
        // SRS-REQ-158: a value that has not been configured is rejected. A state
        // the model does not know is such a value.
        if (! $model->permits($currentState)) {
            throw TransitionRefused::becauseStateIsNotDeclared($model, $currentState);
        }

        // BE-176 ‡: an undeclared transition is refused.
        $destination = $model->destinationOf($currentState, $trigger);

        if ($destination === null) {
            throw TransitionRefused::becauseTransitionIsNotDeclared($model, $currentState, $trigger);
        }

        $transition = StateTransition::of($currentState, $trigger, $destination);

        // BE-177 ‡: after the model, and only ever to refuse.
        foreach ($this->invariants as $invariant) {
            $reason = $invariant->refusalReason($model, $transition, $subject);

            if ($reason !== null) {
                throw TransitionRefused::becauseAnInvariantForbidsIt($model, $transition, $invariant, $reason);
            }
        }

        return $destination;
    }

    /**
     * Whether the transition would be permitted, without applying it.
     *
     * Useful for presenting what an actor may do. It runs the same checks in the
     * same order, so it cannot report a transition as available that
     * {@see apply()} would then refuse.
     */
    public function permits(StateModel $model, string $currentState, string $trigger, ?object $subject = null): bool
    {
        try {
            $this->apply($model, $currentState, $trigger, $subject);
        } catch (TransitionRefused) {
            return false;
        }

        return true;
    }

    /**
     * The invariants in force, for review.
     *
     * `BE-177` ‡ is only checkable if the list of things that hold irrespective
     * of the model can be read.
     *
     * @return list<string>
     */
    public function invariants(): array
    {
        return array_map(
            static fn (TransitionInvariant $invariant): string => $invariant->describe(),
            $this->invariants,
        );
    }
}
