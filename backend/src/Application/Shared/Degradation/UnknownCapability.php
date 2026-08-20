<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Degradation;

use LogicException;

/**
 * Something asked about a capability the register does not declare.
 *
 * A fault rather than a business refusal (`BE-186` ‡): no caller can reach it,
 * because a capability name is a constant in the code that asks. Reaching it
 * means a capability was renamed in one place and not the other.
 *
 * It raises rather than answering `Available`, for the reason `SEC-055` ‡ gives
 * about an unstated authorisation rule and `FRD-FR-256` ‡ gives about a capability
 * whose support is gone: the safe answer to *"may I offer this?"* is never a
 * cheerful yes about something nobody declared.
 */
final class UnknownCapability extends LogicException {}
