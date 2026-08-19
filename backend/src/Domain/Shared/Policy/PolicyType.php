<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Policy;

/**
 * The declared type of a policy value (`BE-166`).
 *
 * `BADR-12` rejected an untyped key-value store in its own words: *"a mistyped
 * cancellation window becomes a runtime failure in a payment path"*. The type is
 * declared with the key, validated when a value is written (`BE-174`), and
 * enforced again when it is read.
 *
 * There is no `Float`. `DB-033` ‡ forbids storing a monetary value in a
 * floating-point type **under any circumstance**, and a policy value may well be
 * monetary — a commission, a fee, a threshold. `Decimal` is carried as an exact
 * string and never converted.
 */
enum PolicyType: string
{
    case Integer = 'integer';

    /** Exact decimal, carried as a string. Never a float (`DB-033` ‡). */
    case Decimal = 'decimal';

    /** A span, in whole seconds. */
    case Duration = 'duration';

    case Boolean = 'boolean';

    case Text = 'text';
}
