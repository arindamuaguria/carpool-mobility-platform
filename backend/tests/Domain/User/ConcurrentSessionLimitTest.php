<?php

declare(strict_types=1);

namespace Tests\Domain\User;

use Cmp\Domain\User\ConcurrentSessionLimit;
use InvalidArgumentException;
use Tests\Domain\DomainTestCase;

/**
 * `SEC-243` ‡ / `SEC-049` — the limit, and what it does at the limit.
 *
 * **Level 2** (`TC-029`): the rule is arithmetic over a count, so this is the
 * earliest level that can verify it conclusively (`TC-033`). That the count comes
 * from `op_sessions` and excludes expired and terminated rows is level 3's, in
 * `Tests\Integration\User\SessionEstablishmentTest`.
 */
final class ConcurrentSessionLimitTest extends DomainTestCase
{
    public function test_the_limit_is_reached_at_the_number_and_not_past_it(): void
    {
        // SEC-049's decided figure is three, and three means a fourth is refused
        // rather than a fifth. Asserted at the boundary in both directions,
        // because off-by-one here silently gives every user one session more
        // than the Project Owner decided.
        $limit = ConcurrentSessionLimit::of(3);

        self::assertFalse($limit->reachedBy(0));
        self::assertFalse($limit->reachedBy(2));
        self::assertTrue($limit->reachedBy(3));
        self::assertTrue($limit->reachedBy(4));
    }

    public function test_the_number_is_carried_and_not_assumed(): void
    {
        // SEC-049 makes it policy configuration. The figure is CMP-DOC-13's and
        // is applied by an operator — nothing here holds a default, so a test
        // that stopped passing three would fail rather than fall back to it.
        self::assertSame(3, ConcurrentSessionLimit::of(3)->permitted());
        self::assertSame(1, ConcurrentSessionLimit::of(1)->permitted());

        $raised = ConcurrentSessionLimit::of(10);

        self::assertFalse($raised->reachedBy(9));
        self::assertTrue($raised->reachedBy(10));
    }

    public function test_a_limit_of_zero_is_refused(): void
    {
        // SEC-049 says concurrent sessions "shall be permitted". Zero permits
        // none, which is not a bound on concurrency but a closed platform — and
        // BE-172 ‡ forbids a policy value reaching a place an absolute rule
        // lives. This is where that value would arrive.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SEC-049: concurrent sessions are permitted/');

        ConcurrentSessionLimit::of(0);
    }

    public function test_a_negative_limit_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ConcurrentSessionLimit::of(-1);
    }

    public function test_a_negative_count_is_refused(): void
    {
        // Not reachable from a COUNT(*), and refused anyway: a count that came
        // back negative would mean the query was wrong, and answering "not
        // reached" would establish a session on the strength of a broken read.
        $this->expectException(InvalidArgumentException::class);

        ConcurrentSessionLimit::of(3)->reachedBy(-1);
    }

    public function test_there_is_no_way_to_ask_it_to_make_room(): void
    {
        // SEC-243 ‡: "No existing session shall be terminated to make room."
        // The rule offers one question and it has a boolean answer; there is no
        // method here that could return which session to evict, so eviction
        // cannot be written against this type at all.
        $methods = get_class_methods(ConcurrentSessionLimit::class);
        sort($methods);

        self::assertSame(['of', 'permitted', 'reachedBy'], $methods);
    }
}
