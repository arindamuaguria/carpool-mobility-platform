<?php

declare(strict_types=1);

namespace Cmp\Domain\Payment\Port;

use Cmp\Domain\Shared\Port\Port;

/**
 * Confirmation that a payment happened.
 *
 * **No operation is declared yet.** `BE-161` records the payment provider as
 * `[TBD – Business Decision Required]`, and the `Payment` aggregate (`BE-017`)
 * does not exist. An operation written now would be written in terms nothing
 * has defined.
 *
 * The port is declared so that the rules attaching to it are attached before
 * anything implements it:
 *
 * - `BE-158` ‡ payment verification is resolved from the provider's confirmation
 *   **only** — never from the client, never from a UPI application's response
 *   (`FRD-FR-124`, `PADR-03`).
 * - `BE-159` ‡ an unconfirmed payment **remains pending**; the platform does not
 *   assume an outcome. That is what `CapabilityOutcome::Unavailable` is for.
 * - `BE-160` provider callbacks are authenticated and processed idempotently
 *   (`AADR-12`: callbacks are triggers, never verifications).
 *
 * Its operations arrive with FEAT-016.
 */
interface PaymentVerificationPort extends Port {}
