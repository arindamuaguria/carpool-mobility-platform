<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Safety;

use Cmp\Application\Safety\GeneratesIncidentReferences;
use Cmp\Domain\Safety\IncidentReference;

/**
 * `DB-023` ‡'s random source, for the identifiers that matter most.
 *
 * `random_bytes()` is the only source in PHP documented to fail rather than
 * degrade — it raises where the platform has no adequate randomness, which is
 * the fail-closed behaviour `SEC-055` ‡ wants. Sixteen bytes, thirty-two
 * hexadecimal characters, the same as every other external identifier.
 *
 * A weak identifier here would be a way to enumerate the people who have raised
 * a safety signal.
 */
final class RandomIncidentReferences implements GeneratesIncidentReferences
{
    private const REFERENCE_BYTES = 16;

    public function next(): IncidentReference
    {
        return IncidentReference::fromString(bin2hex(random_bytes(self::REFERENCE_BYTES)));
    }
}
