<?php

declare(strict_types=1);

namespace Tests\Domain\Shared\Doubles;

use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\Shared\Time\Instant;

/**
 * A clock that does not move.
 *
 * `BE-036` declares the clock as a port precisely so a time-dependent rule is
 * testable. `TC-029` ‡ then requires the level-2 test to run without reading the
 * wall clock, which is what this is for.
 */
final class FixedClock implements Clock
{
    private function __construct(private readonly Instant $instant) {}

    public static function at(string $iso8601): self
    {
        return new self(Instant::fromString($iso8601));
    }

    public function now(): Instant
    {
        return $this->instant;
    }
}
