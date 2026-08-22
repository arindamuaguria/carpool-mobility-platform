<?php

declare(strict_types=1);

namespace Cmp\Domain\Safety;

use Cmp\Domain\User\UserReference;

/**
 * How the platform holds a safety signal.
 *
 * `BE-037`: declared in the Domain, returning domain objects. `BE-192` ‡ is the
 * governing constraint on everything this touches — *"the safety surface shall
 * depend on the minimum set of components required to record and dispatch"* —
 * so there is no search here, no paging, no filtering and no projection.
 * CMP-DOC-10 §12.3 omits all three from the surface for the same reason:
 * *"every dependency is a way to fail."*
 */
interface SafetyIncidentRepository
{
    /**
     * `FRD-FR-185` ‡ / `API-169` ‡ — persisted, and the acknowledgement waits on
     * it.
     *
     * *"Acceptance shall be acknowledged only after the signal is persisted."*
     * So this is the operation the raise path waits for, and there is no
     * write-behind, no buffer and no fire-and-forget anywhere in front of it.
     */
    public function save(SafetyIncident $incident): void;

    /**
     * The incident this reference names, or null.
     *
     * Null rather than a raise: `SEC-069` ‡ requires absence and
     * non-entitlement to be indistinguishable, and the caller decides what to
     * say. `GET /safety/v1/incidents/{id}` reads *own* incidents, so the answer
     * for somebody else's is the answer for one that does not exist.
     */
    public function forReference(IncidentReference $reference): ?SafetyIncident;

    /**
     * `FRD-FR-190` ‡ — every incident that has not reached the operator queue.
     *
     * *"The system shall retain and retry an incident that cannot immediately
     * reach the operator queue."* Retention is the row; this is what makes the
     * retry possible, and it is a **query against state** rather than a memory of
     * what was dispatched — a process that died between persisting and
     * dispatching remembers nothing, and this finds its incident anyway.
     *
     * Oldest first, bounded: an operator draining a backlog wants the earliest
     * signal first, and an unbounded read of this table is the one query that
     * must not fall over when it matters most.
     */
    public function unrouted(int $limit): UnroutedIncidents;

    /**
     * Whether this user raised this incident.
     *
     * `SEC-066` ‡ / `BE-181` ‡: evaluated against platform state, never against
     * an inbound claim. It answers the read's authorisation question without
     * loading a set the safety surface has no other reason to load — `BE-192` ‡
     * again.
     */
    public function wasRaisedBy(IncidentReference $reference, UserReference $user): bool;
}
