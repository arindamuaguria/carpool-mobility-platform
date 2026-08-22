<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Domain\Safety\IncidentReference;

/**
 * Where a new incident identifier comes from.
 *
 * `DB-023` ‡ needs a cryptographically adequate random source and `BE-002` /
 * `TC-029` ‡ keep the Domain free of the environment, so {@see
 * IncidentReference} validates a value and does not produce one.
 *
 * The entropy matters more here than elsewhere: an enumerable identifier on this
 * table would be a way to walk the list of people who have raised a safety
 * signal.
 */
interface GeneratesIncidentReferences
{
    public function next(): IncidentReference;
}
