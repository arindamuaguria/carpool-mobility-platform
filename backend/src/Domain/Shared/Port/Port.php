<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Port;

/**
 * A capability the platform does not itself provide (`BE-149`).
 *
 * Every port obeys the same contract, whatever it is a port for:
 *
 * - **Declared in `Domain`, in domain terms, naming no provider** (`BE-036`,
 *   `BE-150`). A port called after a supplier would have to change when the
 *   supplier did, which is the coupling `BE-162` forbids.
 * - **Returns a {@see CapabilityResult}** (`BE-151`) — `Verified`, `Reported`,
 *   `Unavailable` or `Rejected`, and nothing else.
 * - **Reports; it does not conclude** (`BE-156` ‡). An adapter says what the
 *   provider said. What that means for the business is decided in the Domain,
 *   in exactly one component (`BE-010`).
 * - **Invoked outside a transaction** (`BE-155`, `BE-050` ‡). A provider call
 *   inside a transactional scope holds a row lock for as long as a third party
 *   takes to answer.
 * - **No provider type appears above its adapter** (`BE-153` ‡), which
 *   PortRulesTest fails the build over.
 * - **Has a test adapter exercising every result** (`BE-163`).
 *
 * Timeout, retry and circuit behaviour are adapter concerns governed by
 * configuration (`BE-157`), never by a caller.
 */
interface Port {}
