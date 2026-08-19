<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Transaction;

/**
 * The only way to open a transaction.
 *
 * `BE-047` ‡: **only an application service** begins, commits or rolls back a
 * transaction. Declaring the capability here and forbidding transaction control
 * everywhere else (`TC-037` rule 4) makes that a structural property rather than
 * a convention.
 *
 * `BE-054`: the scope is the narrowest that preserves the required atomicity.
 * `BE-053` ‡: a failed operation leaves no partial effect — the work is rolled
 * back in full, never partly applied.
 *
 * `BE-050` ‡ / `BE-052`: **no external provider call occurs inside the scope.**
 * A service needing a provider result obtains it before opening one. Nothing
 * here can enforce that; `TC-037` rule 7 and review do.
 */
interface TransactionBoundary
{
    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function transactional(callable $work): mixed;
}
