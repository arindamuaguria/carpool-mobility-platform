<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Time;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A point in time, recorded as an instant.
 *
 * `DB-034`: timestamps are stored in a single time zone reference and record the
 * **instant**, not a local wall-clock reading. This type has no time zone to
 * choose from — it is UTC and nothing else — so a local reading cannot be
 * mistaken for one by accident.
 *
 * `BE-002` forbids a framework type in the Domain. `DateTimeImmutable` is a PHP
 * standard-library type, not a framework one, and is kept private here so that
 * the domain's notion of time is this class rather than the standard library's.
 */
final class Instant
{
    private function __construct(private readonly DateTimeImmutable $moment) {}

    public static function fromDateTime(DateTimeImmutable $moment): self
    {
        return new self($moment->setTimezone(new DateTimeZone('UTC')));
    }

    /**
     * @param  string  $value  an ISO-8601 instant
     */
    public static function fromString(string $value): self
    {
        try {
            $moment = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(sprintf('%s is not an instant.', $value), 0, $exception);
        }

        return self::fromDateTime($moment);
    }

    /**
     * The form written to a `DATETIME` column and read back (`DB-034`).
     */
    public function toDatabaseString(): string
    {
        return $this->moment->format('Y-m-d H:i:s.u');
    }

    public function toIso8601(): string
    {
        return $this->moment->format('Y-m-d\TH:i:s.up');
    }

    public function isBefore(self $other): bool
    {
        return $this->moment < $other->moment;
    }

    public function equals(self $other): bool
    {
        return $this->moment == $other->moment;
    }

    public function toDateTime(): DateTimeImmutable
    {
        return $this->moment;
    }
}
