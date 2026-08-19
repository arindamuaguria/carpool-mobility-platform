<?php

declare(strict_types=1);

namespace Tests\Domain\StateMachine\Doubles;

use Cmp\Domain\Shared\StateMachine\StateModel;
use Cmp\Domain\Shared\StateMachine\StateTransition;
use Cmp\Domain\Shared\StateMachine\TransitionInvariant;

/**
 * An invariant that always refuses, used to prove `BE-177` ‡ — a permissive
 * definition does not admit a prohibited outcome.
 *
 * A **test double**. The platform's invariants belong to their aggregates; the
 * three `BADR-13` names concern aggregates that do not exist yet.
 */
final class AlwaysRefusingInvariant implements TransitionInvariant
{
    public function __construct(private readonly string $reason) {}

    /**
     * Narrower than the contract’s `?string` on purpose: this double never
     * stands aside, and saying so in the signature is what makes it a double for
     * the refusing case specifically.
     */
    public function refusalReason(StateModel $model, StateTransition $transition, ?object $subject): string
    {
        return $this->reason;
    }

    public function describe(): string
    {
        return 'a test invariant that always refuses';
    }
}
