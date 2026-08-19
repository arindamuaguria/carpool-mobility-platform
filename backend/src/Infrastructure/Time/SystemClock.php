<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Time;

use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\Shared\Time\Instant;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The clock the platform runs on.
 *
 * The adapter for the `Clock` port declared in the Domain (`BE-036`). It is the
 * only place the wall clock is read, so that `BE-040` holds — a domain test runs
 * without a network or a machine's idea of the time — and so that `DB-034` is
 * satisfied at the source: the instant is taken in UTC and never in local time.
 */
final class SystemClock implements Clock
{
    public function now(): Instant
    {
        return Instant::fromDateTime(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }
}
