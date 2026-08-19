<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Time;

/**
 * The platform's source of the current instant.
 *
 * A port declared in the Domain in domain terms (`BE-036`). It exists so that
 * `BE-040` holds — the domain is unit-testable without a database, a framework
 * or a network — and so that nothing in the domain reads the wall clock
 * directly, which would make a time-dependent rule untestable.
 */
interface Clock
{
    public function now(): Instant;
}
