<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\Shared\Refusal\RefusalReason;

/**
 * Why a session was not established.
 *
 * `API-081` ‡ requires a stable machine-readable identifier and `API-082` ‡ a
 * human-readable default; `API-083` has the client present its own localised text
 * keyed by the identifier and fall back to the default.
 *
 * ## Three cases, and why they are three and not one
 *
 * {@see SessionRefusal} has exactly one case, because `SEC-048` ‡ requires an
 * unknown, terminated and expired token to be **indistinguishable**. Nothing
 * requires that here, and two of these three are cases a requirement says the
 * caller must be able to act on:
 *
 * - `FRD-FR-018` routes an unverified user *"to phone verification rather than
 *   into the application"*, which a client cannot do unless it is told which
 *   refusal this is;
 * - `SEC-243` ‡ requires the concurrent-session refusal to carry *"its own reason
 *   identifier"*, in terms;
 * - `UC-003` E2 has the platform *"refuse access and state that the account is
 *   restricted"*.
 *
 * ## What `SEC-021` bounds, and what it does not
 *
 * `SEC-021`: *"The platform shall not disclose whether a phone number is
 * registered **in response to an authentication attempt**."* `FRD-FR-015` says
 * the same of which credential element was wrong. Both bound what a **failed**
 * attempt discloses, and none of these three follows a failed one — every one of
 * them is reached only after the caller has demonstrated possession of the number
 * (`SEC-015`). A caller who has proved the number is theirs learns nothing from
 * being told the account needs verifying.
 *
 * A failed authentication is refused before any of these, by a single
 * indistinguishable refusal that discloses none of it.
 *
 * ## What is deliberately not said
 *
 * `UC-003` E2 marks *"what the User is told, and any appeal path"* as
 * **`[BLOCKED — BAD-DEC-006, BAD-DEC-016]`**. {@see AccountRestricted}'s default
 * text therefore states the fact E2 states and stops: it names no appeal, no
 * contact, no duration and no ground, because none is decided and `FRD-GAP-024`
 * is the open gap. `API-083` lets a client substitute its own text the day one is
 * written.
 */
enum EstablishmentRefusal: string implements RefusalReason
{
    /** `FRD-FR-018` / `UC-003` E3 — routed to `UC-002` rather than into the application. */
    case VerificationRequired = 'session.verification_required';

    /** `SEC-051` ‡ / `BAD-RULE-010` / `UC-003` E2. */
    case AccountRestricted = 'session.account_restricted';

    /** `SEC-243` ‡ / `SEC-049`. */
    case ConcurrentLimitReached = 'session.concurrent_limit_reached';

    public function identifier(): string
    {
        return $this->value;
    }

    public function defaultText(): string
    {
        return match ($this) {
            // FRD-FR-018: the client's next step is UC-002, and the text says so
            // without naming a screen — API-083 keeps presentation the client's.
            self::VerificationRequired => 'This account needs its phone number verified before it can be used.',

            // UC-003 E2, and nothing beyond it. BAD-DEC-006 and BAD-DEC-016 are
            // open, so no appeal path is offered — offering one that does not
            // exist would be worse than offering none.
            self::AccountRestricted => 'This account is restricted and cannot be used.',

            // SEC-243 ‡. The number is not stated: SEC-049 makes it policy
            // configuration, and a text carrying it would be wrong the moment an
            // operator changed it.
            self::ConcurrentLimitReached => 'This account is already signed in on as many devices as it may be. '
                .'Sign out on another device and try again.',
        };
    }

    /**
     * All three are `StateConflict`, which `API-087` maps to `409`.
     *
     * Each describes the state the account or the user is in rather than a rule
     * declining what they asked for — and each can change: a number gets
     * verified, a session gets terminated. `RuleDeclined` would tell a client to
     * stop trying, which is wrong for all three.
     */
    public function kind(): RefusalKind
    {
        return RefusalKind::StateConflict;
    }
}
