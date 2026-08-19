<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\Shared\Refusal\RefusalReason;

/**
 * Reasons used by the error-model tests.
 *
 * These are **test doubles**, not the platform's reason register. A real reason
 * is declared by the domain area that raises it, and is subject to `API-084`
 * (never removed or repurposed within a version) and `API-086` ‡ / `API-094` ‡
 * (states what was declined, never platform state the caller is not entitled
 * to). Booking refusal reasons belong to FEAT-014 and do not exist yet.
 */
enum TestRefusalReason: string implements RefusalReason
{
    case SeatsNoLongerAvailable = 'booking.seats_no_longer_available';
    case NotEntitled = 'access.not_entitled';

    public function identifier(): string
    {
        return $this->value;
    }

    public function defaultText(): string
    {
        return match ($this) {
            self::SeatsNoLongerAvailable => 'Those seats are no longer available.',
            self::NotEntitled => 'This is not available to you.',
        };
    }

    public function kind(): RefusalKind
    {
        return match ($this) {
            self::SeatsNoLongerAvailable => RefusalKind::StateConflict,
            self::NotEntitled => RefusalKind::RuleDeclined,
        };
    }
}
