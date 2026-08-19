<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\StateMachine;

/**
 * A rule that holds **whatever the declared model says**.
 *
 * `BE-177` ‡ / `ARCH-038`: *"Coded invariants shall hold irrespective of the
 * declared state model; a permissive definition shall not admit a prohibited
 * outcome."* `BADR-13` names three by way of example — no trip without a
 * confirmed booking, no case closed without an outcome, payment restricted to
 * three states — and says they are *"implemented in the domain and are not
 * expressible as configuration."*
 *
 * **An invariant can only refuse.** There is no method here that permits a
 * transition, and that asymmetry is the whole design: a misconfigured model can
 * stall a workflow, which `BADR-13` accepts as a consequence, but it can never
 * admit something the domain forbids.
 *
 * `SRS-REQ-159` ‡ and `SRS-REQ-160` ‡ are the two the chain states outright, and
 * both say *"irrespective of the … model eventually adopted"*.
 */
interface TransitionInvariant
{
    /**
     * The reason this invariant refuses the transition, or null to stand aside.
     *
     * Standing aside is **not** permission. It means this invariant has nothing
     * to say; the model already decided the transition was declared, and another
     * invariant may still refuse it.
     *
     * @param  object|null  $subject  the aggregate the transition concerns, where
     *                                the invariant needs it to decide
     */
    public function refusalReason(StateModel $model, StateTransition $transition, ?object $subject): ?string;

    /**
     * What this invariant protects, for a refusal message and for review.
     */
    public function describe(): string;
}
