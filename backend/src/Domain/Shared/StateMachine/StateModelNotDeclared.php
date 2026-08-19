<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\StateMachine;

use RuntimeException;

/**
 * No definition has been applied for this lifecycle.
 *
 * `SRS-REQ-158`: an attempt to use a state value that has not been configured is
 * rejected. `SRS-REQ-113` forbids synthesising a result, and a permissive
 * default model would be the business policy `BADR-13` refused to invent:
 * *"six are undecided; this is inventing business policy."*
 *
 * **This is what an open business decision feels like at run time**, and it is
 * the intended behaviour rather than a shortcoming. Six of the ten models in
 * CMP-DOC-06 §7.2 are undefined: account state (`BAD-DEC-006`), verification
 * standing (`BAD-DEC-005`), ride request (`BAD-DEC-007`), booking and trip
 * (`BAD-DEC-015`), and the amendment, response and closure parts of three more.
 */
final class StateModelNotDeclared extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self(sprintf(
            'SRS-REQ-158: no state model named "%s" has been configured. CMP-DOC-06 §7.2 records six of '
            .'the ten models as undefined pending a business decision; no default is invented.',
            $name,
        ));
    }
}
