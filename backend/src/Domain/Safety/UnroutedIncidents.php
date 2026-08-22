<?php

declare(strict_types=1);

namespace Cmp\Domain\Safety;

/**
 * The incidents that have not reached the operator queue.
 *
 * `BE-037`'s requirement, and its stated reason: *"an array of rows is the ORM
 * leaking through a contract that promised not to leak it, and an array of
 * domain objects should be expressed as a collection type in the Domain when one
 * is needed."* {@see SafetyIncidentRepository::unrouted()} needs one.
 *
 * ## It carries the bound it was read under
 *
 * `FRD-FR-190` ‡'s retry works a batch, and `BD-04` requires the query to be
 * bounded — an unbounded read of this table is the one that must not fall over
 * when it matters most. {@see mayBeMore()} is how a caller knows a full batch is
 * not the whole backlog, which is the difference between *"nothing is
 * outstanding"* and *"nothing more fitted in this pass"*.
 *
 * Without it, a retry that returned a full batch and a retry that cleared the
 * backlog would look identical — and an operator would read the second where the
 * truth was the first.
 */
final class UnroutedIncidents
{
    /**
     * @param  list<SafetyIncident>  $incidents
     */
    private function __construct(
        private readonly array $incidents,
        private readonly int $bound,
    ) {}

    public static function of(int $bound, SafetyIncident ...$incidents): self
    {
        return new self(array_values($incidents), $bound);
    }

    public static function none(int $bound): self
    {
        return new self([], $bound);
    }

    /**
     * @return list<SafetyIncident>
     */
    public function all(): array
    {
        return $this->incidents;
    }

    public function count(): int
    {
        return count($this->incidents);
    }

    public function isEmpty(): bool
    {
        return $this->incidents === [];
    }

    /**
     * True where the batch filled its bound, so a further pass may find more.
     */
    public function mayBeMore(): bool
    {
        return count($this->incidents) >= $this->bound;
    }
}
