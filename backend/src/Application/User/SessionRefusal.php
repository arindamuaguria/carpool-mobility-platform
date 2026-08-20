<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\Shared\Refusal\RefusalReason;

/**
 * What a caller is told when a session will not serve.
 *
 * `SEC-048` ‡: *"A request bearing a terminated, expired or unknown token shall
 * be refused **identically**, so that the three are indistinguishable to a
 * caller."* `API-107` says the same of the shape.
 *
 * **There is therefore one case here.** Adding a second — a distinct
 * `session.expired`, say — would make the three distinguishable and hand a caller
 * a way to learn that a token was once real. `AuthorisationRefusal` has exactly
 * one case for exactly this reason, and only one case is what makes the mistake
 * hard to make by accident.
 *
 * The distinction is kept internally, in {@see SessionRefusalCause}, where an
 * operator reading the evidential log can see which of the three it was.
 *
 * `RefusalKind::StateConflict` rather than `RuleDeclined`: the platform declines
 * because of the state a session is in, and `API-087` maps a state conflict to
 * `409`. A caller whose session expired should retry after re-authenticating,
 * which is a state that can change — a rule declining would say it cannot.
 */
enum SessionRefusal: string implements RefusalReason
{
    case NotUsable = 'session.not_usable';

    public function identifier(): string
    {
        return $this->value;
    }

    public function defaultText(): string
    {
        return 'This session is no longer usable. Please sign in again.';
    }

    public function kind(): RefusalKind
    {
        return RefusalKind::StateConflict;
    }
}
