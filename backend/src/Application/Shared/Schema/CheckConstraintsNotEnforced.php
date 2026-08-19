<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Schema;

use RuntimeException;

/**
 * The deployed database accepted a write violating a `CHECK` constraint.
 *
 * Fourteen of the twenty-one constraints in the CMP-DOC-11 §15 register rely on
 * `CHECK` being enforced. On a server that does not enforce it they are
 * decoration, and `OPS-024` ‡ is breached.
 */
final class CheckConstraintsNotEnforced extends RuntimeException {}
