<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

/**
 * What the platform will let an account do.
 *
 * `BAD-RULE-010`, decided by the Project Owner on 2026-08-20 and **Absolute**:
 * the permitted states are `ACTIVE`, `SUSPENDED` and `DEACTIVATED`; `ACTIVE`
 * permits normal authenticated use and the other two prevent it.
 *
 * ## Why this is a Domain enum and not policy configuration
 *
 * `DB-045` says account state *"shall reference policy configuration rather than
 * a database `ENUM`"*, and the schema honours that — `op_users.account_state` is
 * a string, so the value set can change without a migration. **The value set
 * itself is not configuration**, because `BAD-RULE-010` is an absolute business
 * rule: `BE-012` puts an absolute rule in the Domain and out of reach of
 * override, and `BE-172` ‡ and `API-191` ‡ forbid a policy value from relaxing
 * one. A configurable state set would be a configuration that could permit
 * authenticated use for a suspended account, which is the single thing this rule
 * exists to prevent.
 *
 * ## `SEC-051` ‡, made structural
 *
 * *"No session shall be established for a caller whose account state does not
 * permit it."* {@see permitsAuthenticatedUse()} is that question, asked of the
 * state itself. It is the only place the answer is written, so a caller cannot
 * arrive at a different one by reasoning about the cases.
 *
 * ## There is no transition here, and that is the current position
 *
 * `BAD-RULE-010` also says a `SUSPENDED` or `DEACTIVATED` account shall not
 * regain an active session *"other than through a defined account-state
 * transition"* — and **no such transition is defined**. Who may perform one, on
 * what grounds and with what appeal is `FRD-GAP-024`, Critical and open on
 * `BAD-DEC-006` and `BAD-DEC-016`.
 *
 * So this enum offers no transition, and {@see User} has no method that changes
 * an account state. That is not an omission to be filled in later by whoever
 * needs one: it is the rule as decided, and the transition arrives with the
 * decision that defines it.
 */
enum AccountState: string
{
    case Active = 'ACTIVE';

    case Suspended = 'SUSPENDED';

    case Deactivated = 'DEACTIVATED';

    /**
     * `SEC-051` ‡ / `BAD-RULE-010`: only `ACTIVE` permits normal authenticated
     * use.
     *
     * Written as an equality rather than as a `match` over the three, so that a
     * fourth state added without thought would deny rather than permit —
     * `SEC-055` ‡'s deny-by-default reasoning, applied to a value set.
     */
    public function permitsAuthenticatedUse(): bool
    {
        return $this === self::Active;
    }
}
