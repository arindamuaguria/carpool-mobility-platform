<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Refusal;

/**
 * `API-087`: a refusal arising from a state conflict is distinguished from one
 * arising from a rule.
 *
 * The interface layer maps `StateConflict` to `409` and `RuleDeclined` to `422`
 * (CMP-DOC-10 §8.1). The domain does not know those numbers; `BE-005` keeps
 * transport at the interface.
 */
enum RefusalKind
{
    /** The operation conflicts with the platform's current state. */
    case StateConflict;

    /** A rule declined the operation outright. */
    case RuleDeclined;
}
