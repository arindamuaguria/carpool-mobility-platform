<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

use Cmp\Domain\Shared\Refusal\BusinessRefusal;

/**
 * The actor may not perform the operation.
 *
 * A **business refusal** (`BADR-17`), which CMP-DOC-10 §8.6 states outright:
 * *"Caller not entitled → Business refusal … Never a not-found, which would leak
 * existence."* `BE-186` ‡ forbids representing it as an internal fault, and
 * `BE-182` makes it a distinct outcome from absence and from validation failure.
 *
 * The **cause** is carried for the record (`SEC-057` ‡) and for an operator. The
 * **reason** a caller receives is one identifier for every cause, because
 * `SEC-069` ‡ requires absence and non-entitlement to be indistinguishable.
 */
final class AuthorisationRefused extends BusinessRefusal
{
    private function __construct(
        private readonly Operation $operation,
        private readonly AuthorisationRefusalCause $cause,
    ) {
        parent::__construct(AuthorisationRefusal::NotAvailableToYou);
    }

    public static function because(Operation $operation, AuthorisationRefusalCause $cause): self
    {
        return new self($operation, $cause);
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    /**
     * Why it was refused. **Not for a caller** — `SEC-069` ‡ and `API-086` ‡
     * limit what a refusal may disclose, and the caller is shown
     * {@see AuthorisationRefusal} alone.
     */
    public function cause(): AuthorisationRefusalCause
    {
        return $this->cause;
    }
}
