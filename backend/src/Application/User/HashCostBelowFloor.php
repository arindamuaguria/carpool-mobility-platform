<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use RuntimeException;

/**
 * The configured hash cost is beneath the floor the platform will hash at.
 *
 * `SEC-244` ‡: *"the platform shall **refuse to hash** where a configured cost is
 * below the floor of 19456 KiB memory, 2 iterations and 1 lane."*
 *
 * ## Why this is a fault and not a business refusal
 *
 * `BE-186` ‡ separates a decision the platform took from a fault it suffered. A
 * cost below the floor is neither the caller's doing nor a rule declining them:
 * it is a misconfiguration, and `SRS-REQ-158` requires an attempt to use an
 * unconfigured — here, an unsafely configured — value to be **rejected**. The
 * caller sees `API-092` ‡'s internal-fault branch, which names no component and
 * carries only a correlation identity, so the misconfiguration is not disclosed
 * to whoever provoked it.
 *
 * ## Why refusing is better than clamping to the floor
 *
 * Silently raising a too-low value to the floor would leave the operator's
 * configuration disagreeing with the platform's behaviour, and the disagreement
 * would be invisible — every hash would look successful. `SEC-030` requires the
 * parameters to be *"re-tuned when hardware changes"*, which is an act performed
 * deliberately by somebody reading a number; a number the platform quietly
 * overrides is a number nobody re-tunes.
 */
final class HashCostBelowFloor extends RuntimeException {}
