<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Refusal;

use RuntimeException;

/**
 * A rule declined the operation.
 *
 * One of the four branches of `BADR-17` / `BE-185`. Raised in the Domain, where
 * the rule lives (`BE-010`, `BE-012`), and converted by the application service
 * into the corresponding failure result (`BE-046`).
 *
 * `BE-186` ‡ / `API-073` ‡ / `API-074` ‡: a business refusal is never
 * represented as an internal fault, and an internal fault is never represented
 * as a business refusal. `BE-187` ‡ / `API-075` ‡: a dependency unavailability
 * is never represented as a business refusal — nothing was decided, and a
 * refusal asserts that something was.
 *
 * `BE-188`: the refusal carries a reason fit to be shown to the affected person.
 *
 * Extends `RuntimeException` from the PHP standard library, not from a
 * framework: `BE-002` forbids a framework type in the Domain namespace.
 */
class BusinessRefusal extends RuntimeException
{
    public function __construct(private readonly RefusalReason $reason)
    {
        parent::__construct($reason->defaultText());
    }

    public function reason(): RefusalReason
    {
        return $this->reason;
    }

    public function kind(): RefusalKind
    {
        return $this->reason->kind();
    }
}
