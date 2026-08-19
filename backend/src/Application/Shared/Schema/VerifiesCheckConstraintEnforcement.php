<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Schema;

/**
 * Proves that the deployed database enforces `CHECK` constraints.
 *
 * `OPS-024` ‡ requires every environment to deploy a server that enforces
 * `CHECK`; `DB-217` ‡ / `TC-047` ‡ require it to be verified **by attempting a
 * violating write**, because a server that accepts and ignores the clause looks
 * identical in the schema.
 */
interface VerifiesCheckConstraintEnforcement
{
    /**
     * @throws CheckConstraintsNotEnforced when a violating write is accepted
     */
    public function verify(): void;
}
