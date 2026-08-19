<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Failure;

/**
 * The failure half of an application service result (`BE-046`).
 *
 * Expressed in application terms, not transport terms: the interface layer maps
 * a branch to a status code and a body shape (`BADR-17`, `BE-005`), and the same
 * failure reaches the REST, administrative, safety and worker callers unchanged
 * (`BE-013`, `BE-043`).
 *
 * Exactly four subclasses exist, one per branch of `API-071` ‡. Each is final;
 * the set is closed and asserted in ErrorModelStructureTest.
 */
abstract class Failure
{
    abstract public function branch(): FailureBranch;
}
