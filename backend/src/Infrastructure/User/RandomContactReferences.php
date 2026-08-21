<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\User;

use Cmp\Application\User\GeneratesContactReferences;
use Cmp\Domain\User\EmergencyContactReference;

/**
 * `DB-023` ‡'s random source.
 *
 * *"Generated with sufficient entropy that enumeration is infeasible, and shall
 * encode no meaning, no sequence and no timestamp."* `random_bytes()` is the
 * only source in PHP that is documented to fail rather than degrade — it raises
 * where the platform has no adequate randomness, which is the behaviour
 * `SEC-055` ‡'s fail-closed disposition wants and the reason
 * {@see RandomSessionTokens} uses it too.
 *
 * Sixteen bytes, rendered as thirty-two hexadecimal characters, matching every
 * other external identifier the platform issues.
 */
final class RandomContactReferences implements GeneratesContactReferences
{
    private const REFERENCE_BYTES = 16;

    public function next(): EmergencyContactReference
    {
        return EmergencyContactReference::fromString(bin2hex(random_bytes(self::REFERENCE_BYTES)));
    }
}
