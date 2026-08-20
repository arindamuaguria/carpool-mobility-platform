<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Degradation;

/**
 * How a capability stands, given what it depends on (`FRD-FR-256` ‡).
 *
 * *"The system shall withdraw or mark an affected capability rather than present
 * it as working."* Three cases, and the third is the one the requirement exists
 * to forbid being silently produced: a capability whose support is gone and
 * which is reported as working anyway.
 *
 * There is no `Degraded` that also means usable-with-caveats beyond
 * {@see Marked}, and no `Unknown`. `SRS-REQ-113` forbids synthesising a result
 * and `FRD-FR-258` ‡ forbids resolving an unknown outcome by assumption; a
 * standing nobody could compute would be one or the other.
 */
enum CapabilityStanding: string
{
    /** Everything it depends on is answering. */
    case Available = 'AVAILABLE';

    /**
     * Reduced, and honest about it (`FRD-FR-256` ‡'s *"mark"*).
     *
     * `UC-081` A1 gives the shape: with mapping unavailable, *"tracking may
     * continue without map context"*. The capability still does something real;
     * the client is told what is missing from it.
     */
    case Marked = 'MARKED';

    /**
     * Not offered at all (`FRD-FR-256` ‡'s *"withdraw"*, and `FRD-FR-259` ‡'s
     * only permitted outcome for a capability that cannot be degraded).
     *
     * `UC-081` E1: *"Seats, bookings and payments are never approximated."*
     */
    case Withdrawn = 'WITHDRAWN';

    /**
     * `FRD-FR-256` ‡: whether a client may be offered this.
     *
     * Written as an equality rather than as a `match` over the three, so that a
     * fourth case added without thought is **not** offered — the same
     * deny-by-default reasoning `SEC-055` ‡ applies to an unstated rule.
     */
    public function mayBeOffered(): bool
    {
        return $this === self::Available;
    }
}
