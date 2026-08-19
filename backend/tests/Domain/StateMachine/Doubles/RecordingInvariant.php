<?php

declare(strict_types=1);

namespace Tests\Domain\StateMachine\Doubles;

use Cmp\Domain\Shared\StateMachine\StateModel;
use Cmp\Domain\Shared\StateMachine\StateTransition;
use Cmp\Domain\Shared\StateMachine\TransitionInvariant;

/**
 * An invariant that records what it was asked about and stands aside.
 *
 * Standing aside is **not** permission — it means this invariant has nothing to
 * say. The double exists to show that an invariant is never consulted about a
 * transition the model did not declare, and that standing aside cannot make an
 * undeclared transition happen.
 */
final class RecordingInvariant implements TransitionInvariant
{
    /** @var list<string> */
    private array $seen = [];

    public function refusalReason(StateModel $model, StateTransition $transition, ?object $subject): ?string
    {
        $this->seen[] = $transition->describe();

        return null;
    }

    public function describe(): string
    {
        return 'a test invariant that records and stands aside';
    }

    /**
     * @return list<string>
     */
    public function seen(): array
    {
        return $this->seen;
    }
}
