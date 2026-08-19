<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Refusal;

/**
 * The identified reason a rule declined an operation.
 *
 * `AADR-14` / `API-081` ‡: a business refusal carries a **stable machine-readable
 * identifier**, never free text. `API-082` ‡: it also carries a human-readable
 * default suitable for presentation, which the client replaces with its own
 * localised text keyed by the identifier (`API-083`).
 *
 * `API-084`: an identifier is never removed or repurposed within an interface
 * version. `API-085`: adding one is non-breaking.
 *
 * `API-086` ‡ / `API-094` ‡ constrain what a reason may say: it states what the
 * platform declined and never states platform state the caller is not entitled
 * to — absence and non-entitlement must be indistinguishable, so that existence
 * cannot be probed.
 *
 * Each domain area declares its own reasons as an enum implementing this
 * interface, so that the set is closed and inspectable per area.
 */
interface RefusalReason
{
    /**
     * The stable identifier. Machine-readable, never localised, never reused.
     */
    public function identifier(): string;

    /**
     * The human-readable default, suitable for presentation to the affected
     * person where the client has no localised text for the identifier.
     */
    public function defaultText(): string;

    /**
     * Whether the refusal arises from a conflict with current state or from a
     * rule declining outright (`API-087`).
     */
    public function kind(): RefusalKind;
}
