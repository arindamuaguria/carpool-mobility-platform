<?php

declare(strict_types=1);

namespace Cmp\Domain\User\Port;

use Cmp\Domain\Shared\Port\Port;

/**
 * Confirmation that a person is who they say they are.
 *
 * **No operation is declared yet.** `BAD-DEC-005` — the verification policy —
 * is open, so what the platform asks a provider to verify is undecided, and
 * `BE-161` records the provider as `[TBD – Business Decision Required]`.
 *
 * `BE-033` ‡ attaches here in advance: **verification standing is domain state
 * and is never accepted from an inbound value.** A `Reported` result from this
 * port is what a provider said, and `SRS-REQ-106` forbids it becoming a fact.
 *
 * Its operations arrive with the verification policy.
 */
interface IdentityVerificationPort extends Port {}
