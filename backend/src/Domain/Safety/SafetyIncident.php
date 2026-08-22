<?php

declare(strict_types=1);

namespace Cmp\Domain\Safety;

use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\UserReference;
use LogicException;

/**
 * `BE-017`'s eighth aggregate — a safety signal, as the platform holds it.
 *
 * `FRD-FR-185` ‡: *"The system shall record **every** safety signal it receives
 * as a safety incident."* `FRD-FR-188` ‡: *"shall never discard a safety
 * signal."* Between them there is no state in which this object refuses to
 * exist, and that is the design rather than an omission — `BD-04` puts it as
 * *"no safety signal may be lost, under any load or failure"*, and `DB-077` ‡
 * carries it into the schema.
 *
 * ## What it will not do
 *
 * There is **no `close()`**. `BE-029` ‡ requires an outcome for closure, so the
 * only way to close is {@see closedWith()}, which takes one — the same shape
 * `ConcurrentSessionLimit` uses for `SEC-243` ‡: a rule made unbreakable by
 * there being no method that breaks it. `API-171` ‡ adds that no client
 * interface may close an incident, and none does; the closure path has no caller
 * anywhere in the platform, because `UC-052` is Outlined pending `BAD-DEC-011`.
 *
 * There is **no `notify()`, no `dispatch()` and no `escalate()`**. `GAP-004`
 * leaves emergency dispatch open, `BRD-REQ-114` leaves emergency-contact
 * notification blocked on `BAD-DEC-011`, and CMP-DOC-10 §12.4 states the
 * position: *"No operation is specified. The incident resource exists so that
 * the absence of a dispatch path is **visible rather than assumed**."*
 * `SafetyIncidentSurfaceTest` asserts the method list, so the absence stays
 * visible to a build and not only to a reader.
 *
 * ## Routing is a fact about the record, not a capability of it
 *
 * `FRD-FR-189` ‡ places every incident in the safety operator queue and
 * `FRD-FR-190` ‡ requires one that could not reach it to be retained and
 * retried. {@see routedAt()} is how an unrouted incident is found again — a
 * query, not a memory of what was dispatched — and {@see routed()} is what marks
 * it once the queue has it.
 */
final class SafetyIncident
{
    private function __construct(
        private readonly IncidentReference $reference,
        private readonly UserReference $raisedBy,
        private readonly Instant $raisedAt,
        private readonly IncidentContext $context,
        private ?Instant $routedAt,
        private readonly ?Instant $closedAt,
        private readonly ?IncidentOutcome $outcome,
    ) {}

    /**
     * `FRD-FR-185` ‡ — the signal becomes a record.
     *
     * Nothing about this can fail. The raiser comes from the session
     * (`SEC-044` ‡), the instant from the platform clock, and the context is
     * whatever the platform could obtain — `FRD-FR-187` ‡ makes an incomplete
     * context a recordable state rather than a refusable one.
     */
    public static function raise(
        IncidentReference $reference,
        UserReference $raisedBy,
        Instant $raisedAt,
        IncidentContext $context,
    ): self {
        return new self($reference, $raisedBy, $raisedAt, $context, null, null, null);
    }

    /**
     * Rebuilt from the store.
     */
    public static function reconstitute(
        IncidentReference $reference,
        UserReference $raisedBy,
        Instant $raisedAt,
        IncidentContext $context,
        ?Instant $routedAt,
        ?Instant $closedAt,
        ?IncidentOutcome $outcome,
    ): self {
        if ($closedAt !== null && $outcome === null) {
            // BE-029 ‡, enforced on the way in as well as on the way out. A row
            // in this state would mean the invariant had already been broken by
            // something that bypassed the aggregate, and reading it back
            // silently would make the record look lawful.
            throw new LogicException(
                'BE-029 ‡: a closed safety incident carries a recorded outcome, and this stored one does not.'
            );
        }

        return new self($reference, $raisedBy, $raisedAt, $context, $routedAt, $closedAt, $outcome);
    }

    public function reference(): IncidentReference
    {
        return $this->reference;
    }

    public function raisedBy(): UserReference
    {
        return $this->raisedBy;
    }

    public function raisedAt(): Instant
    {
        return $this->raisedAt;
    }

    public function context(): IncidentContext
    {
        return $this->context;
    }

    public function routedAt(): ?Instant
    {
        return $this->routedAt;
    }

    public function isRouted(): bool
    {
        return $this->routedAt !== null;
    }

    public function closedAt(): ?Instant
    {
        return $this->closedAt;
    }

    public function isClosed(): bool
    {
        return $this->closedAt !== null;
    }

    public function outcome(): ?IncidentOutcome
    {
        return $this->outcome;
    }

    /**
     * `FRD-FR-189` ‡ — it reached the operator queue.
     *
     * Idempotent, and it has to be: `BE-135` ‡ makes a job safe to execute more
     * than once, and a queue redelivers. The **first** instant is kept, because
     * what `FRD-FR-190` ‡ cares about is when the signal reached the queue, not
     * when a worker last confirmed that it had.
     */
    public function routed(Instant $at): void
    {
        $this->routedAt ??= $at;
    }

    /**
     * `BE-029` ‡ — the only way to close, and it takes an outcome.
     *
     * **Nothing calls this.** `UC-052` is Outlined pending `BAD-DEC-011`, the
     * outcome vocabulary is part of a protocol nobody has decided, and
     * `API-171` ‡ forbids a client interface closing an incident at all. It
     * exists so that the invariant is a property of the type rather than of a
     * caller's care — when the response protocol arrives, there is no other door.
     */
    public function closedWith(IncidentOutcome $outcome, Instant $at): self
    {
        return new self(
            $this->reference,
            $this->raisedBy,
            $this->raisedAt,
            $this->context,
            $this->routedAt,
            $at,
            $outcome,
        );
    }
}
