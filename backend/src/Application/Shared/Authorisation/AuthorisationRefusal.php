<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\Shared\Refusal\RefusalReason;

/**
 * What a caller is told when an authorisation is refused — and it is the same
 * thing they are told when the record does not exist.
 *
 * `SEC-069` ‡ / `API-094` ‡: absence and non-entitlement are indistinguishable
 * to a caller. CMP-DOC-10 §8.6 spells out both halves: *"Caller not entitled →
 * Business refusal. Never a not-found, which would leak existence"*, and
 * *"Resource does not exist → Business refusal. Same shape as not-entitled, so
 * the two are indistinguishable to a caller."*
 *
 * There is therefore **one** case here. Adding a second — a distinct
 * *"not found"* — would be the leak both statements forbid, and the enum having
 * only one case is what makes that hard to do by accident.
 *
 * `BE-182` is satisfied elsewhere: the refusal is a distinct outcome internally,
 * recorded with its {@see AuthorisationRefusalCause}.
 */
enum AuthorisationRefusal: string implements RefusalReason
{
    case NotAvailableToYou = 'access.not_available_to_you';

    public function identifier(): string
    {
        return $this->value;
    }

    public function defaultText(): string
    {
        // Says nothing about whether the record exists (API-086 ‡).
        return 'This is not available to you.';
    }

    public function kind(): RefusalKind
    {
        // API-087: a rule declined the request, rather than a conflict with the
        // record's current state.
        return RefusalKind::RuleDeclined;
    }
}
