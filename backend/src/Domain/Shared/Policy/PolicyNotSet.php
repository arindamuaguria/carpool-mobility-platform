<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Policy;

use RuntimeException;

/**
 * A declared policy value has no value set.
 *
 * `SRS-REQ-158`: an attempt to use a value that has not been configured is
 * **rejected**. `SRS-REQ-113` forbids synthesising a result, and a default
 * invented here would be a business decision taken by the platform on nobody's
 * authority.
 *
 * Eleven policy values are listed in CMP-DOC-09 §13.2 precisely because eleven
 * decisions are outstanding. This exception is what an outstanding decision
 * feels like at run time, and that is the intended behaviour rather than a
 * shortcoming.
 */
final class PolicyNotSet extends RuntimeException
{
    public static function forKey(PolicyKey $key): self
    {
        return new self(sprintf(
            'SRS-REQ-158: "%s" is declared but has no value. It is read to decide: %s. '
            .'No default is invented — the decision is outstanding.',
            $key->name(),
            $key->purpose(),
        ));
    }
}
