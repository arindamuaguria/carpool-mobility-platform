<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Port;

/**
 * The four outcomes a port may return, and no fifth (`BE-151`).
 *
 * `BADR-11` exists for one reason: `SRS-REQ-106` requires that *"the provider
 * reported X"* never becomes *"X is true"*. Separating `Verified` from
 * `Reported` makes that a property of the type rather than of the caller's care.
 *
 * `SRS-REQ-113` forbids synthesising a result. There is no `Assumed`.
 */
enum CapabilityOutcome
{
    /**
     * The platform has confirmation it may treat as fact.
     *
     * `BE-158` ‡: payment verification is resolved from the provider's
     * confirmation **only**.
     */
    case Verified;

    /**
     * The provider said something. It is not a fact, and nothing may treat it as
     * one — `FRD-FR-124` and `PADR-03` forbid treating a UPI application's
     * response as evidence of payment.
     */
    case Reported;

    /**
     * The capability did not answer. **Nothing was decided.**
     *
     * `BE-152` ‡: never treated as success and never as failure.
     * `FRD-FR-258` ‡: an unknown outcome is not resolved by assumption in either
     * direction. `BE-159` ‡: an unconfirmed payment stays pending.
     */
    case Unavailable;

    /**
     * The provider declined. What that means for the business is the domain's to
     * decide (`BE-156` ‡) — an adapter reports; it does not conclude.
     */
    case Rejected;
}
