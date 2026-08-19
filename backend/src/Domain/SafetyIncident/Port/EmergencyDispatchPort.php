<?php

declare(strict_types=1);

namespace Cmp\Domain\SafetyIncident\Port;

use Cmp\Domain\Shared\Port\Port;

/**
 * Reaching an emergency response capability.
 *
 * **This port exists precisely so that its absence is visible rather than
 * assumed** — `BE-164` says so in those words, and records the integration as
 * `GAP-004`.
 *
 * **Nothing may implement it.** `BAD-DEC-011` is open and no response capability
 * is staffed. `BAD-RISK-005`: a safety control with no response behind it is a
 * liability, not a feature. `FRD-FR-195` makes the withholding testable, and
 * `ADM-187`/`ADM-191` forbid stubbing, prototyping, hiding behind a role,
 * disabling, flagging or marking it "coming soon".
 *
 * An adapter for this port is not "not yet written". It is **withheld**, and
 * PortRulesTest fails the build if one appears.
 */
interface EmergencyDispatchPort extends Port {}
