<?php

declare(strict_types=1);

namespace Tests\Domain\Degradation\Doubles;

use Cmp\Application\Shared\Degradation\ObservesSupport;
use Cmp\Domain\Shared\Degradation\Support;

/**
 * An observer whose answers the test states, and which counts what it was asked.
 *
 * `TC-029` keeps a domain test away from a network, and detecting whether a
 * supporting service is answering otherwise needs one. What is exercised is the
 * computation over the answers, not the answering.
 *
 * The count exists for one assertion that would otherwise be unwritable: a
 * dependency two capabilities share must be observed **once**, because two
 * observations could disagree and `FRD-FR-255` would then report one capability
 * affected and another not, by the same cause.
 *
 * {@see restoreEverything()} is `FRD-FR-260`'s test in one call — the same
 * observer, a changed answer, and no reset anywhere.
 */
final class StatedSupport implements ObservesSupport
{
    /** @var list<Support> */
    private array $missing;

    private int $observations = 0;

    /**
     * @param  list<Support>  $missing  what is not answering
     */
    public function __construct(array $missing)
    {
        $this->missing = array_values($missing);
    }

    public function isAnswering(Support $support): bool
    {
        $this->observations++;

        foreach ($this->missing as $absent) {
            if ($support->equals($absent)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `FRD-FR-260`: the supporting service comes back.
     */
    public function restoreEverything(): void
    {
        $this->missing = [];
    }

    public function observationCount(): int
    {
        return $this->observations;
    }
}
