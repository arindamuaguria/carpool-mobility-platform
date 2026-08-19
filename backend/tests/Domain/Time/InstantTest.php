<?php

declare(strict_types=1);

namespace Tests\Domain\Time;

use Cmp\Domain\Shared\Time\Instant;
use Cmp\Infrastructure\Time\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Tests\Domain\DomainTestCase;

/**
 * CMP-IMP-026 — the instant a domain event carries.
 *
 * `DB-034`: a timestamp is stored in a single time zone reference and records
 * the **instant**, not a local wall-clock reading. A local reading stored as if
 * it were an instant is the kind of defect that is invisible until a trip
 * crosses an hour boundary.
 */
final class InstantTest extends DomainTestCase
{
    public function test_a_local_reading_is_converted_to_the_single_time_zone_reference(): void
    {
        // DB-034. 15:00 in Asia/Kolkata is 09:30 UTC; the instant is the same
        // moment, and only one of those two readings may be stored.
        $local = new DateTimeImmutable('2026-08-19 15:00:00', new DateTimeZone('Asia/Kolkata'));

        self::assertSame('2026-08-19 09:30:00.000000', Instant::fromDateTime($local)->toDatabaseString());
    }

    public function test_two_readings_of_the_same_moment_are_the_same_instant(): void
    {
        $kolkata = Instant::fromDateTime(new DateTimeImmutable('2026-08-19 15:00:00', new DateTimeZone('Asia/Kolkata')));
        $utc = Instant::fromString('2026-08-19T09:30:00Z');

        self::assertTrue($kolkata->equals($utc));
    }

    public function test_an_instant_orders_against_another(): void
    {
        $earlier = Instant::fromString('2026-08-19T09:30:00Z');
        $later = Instant::fromString('2026-08-19T09:30:01Z');

        self::assertTrue($earlier->isBefore($later));
        self::assertFalse($later->isBefore($earlier));
    }

    public function test_the_database_form_carries_sub_second_precision(): void
    {
        // An evidential chain is ordered by internal key (DB-111 ‡), but two
        // records written in the same second must still be distinguishable when
        // read back by time.
        self::assertSame(
            '2026-08-19 09:30:00.123456',
            Instant::fromString('2026-08-19T09:30:00.123456Z')->toDatabaseString(),
        );
    }

    public function test_a_value_that_is_not_an_instant_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Instant::fromString('the day before yesterday');
    }

    public function test_the_system_clock_reports_the_current_instant_in_the_single_reference(): void
    {
        $now = (new SystemClock)->now();

        self::assertSame(0, $now->toDateTime()->getOffset(), 'DB-034: the single time zone reference.');
    }
}
