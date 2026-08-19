<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\StateMachine;

use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\Shared\Refusal\RefusalReason;

/**
 * Why a lifecycle transition was refused.
 *
 * `API-081` ‡: a stable machine-readable identifier. `API-082` ‡: a
 * human-readable default suitable for presentation.
 *
 * **Two of the three cases deliberately share one identifier.** `API-086` ‡ and
 * `API-094` ‡ limit what a refusal may tell a caller about platform state, and a
 * caller who could tell *"not a declared transition"* from *"declared, but an
 * invariant forbids it"* could map the platform's lifecycle — and the rules
 * behind it — by probing. The distinction is kept internally, where an operator
 * needs it, and reaches the caller as one answer.
 *
 * The enum is not backed, precisely so that a case can carry an identifier
 * another case also carries.
 */
enum StateMachineRefusal implements RefusalReason
{
    /** The state itself is not one the model declares (`SRS-REQ-158`). */
    case StateNotDeclared;

    /** The model declares no such transition from here (`BE-176` ‡). */
    case TransitionNotDeclared;

    /** A coded invariant forbids it whatever the model says (`BE-177` ‡). */
    case InvariantForbidsIt;

    public function identifier(): string
    {
        return match ($this) {
            self::StateNotDeclared => 'lifecycle.state_not_declared',
            // API-094 ‡: indistinguishable to a caller.
            self::TransitionNotDeclared, self::InvariantForbidsIt => 'lifecycle.transition_not_permitted',
        };
    }

    public function defaultText(): string
    {
        return match ($this) {
            self::StateNotDeclared,
            self::TransitionNotDeclared,
            self::InvariantForbidsIt => 'This cannot be done from its current state.',
        };
    }

    public function kind(): RefusalKind
    {
        // API-087: a conflict with the current state, not a rule declining the
        // request on its own merits.
        return RefusalKind::StateConflict;
    }
}
