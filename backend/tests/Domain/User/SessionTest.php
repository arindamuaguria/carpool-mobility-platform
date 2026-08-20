<?php

declare(strict_types=1);

namespace Tests\Domain\User;

use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\UserReference;
use InvalidArgumentException;
use ReflectionClass;
use Tests\Domain\DomainTestCase;

/**
 * `CMP-IMP-055` — what a session is, and when it stops being usable.
 *
 * Level 2 (`TC-029` ‡). `SEC-039` ‡'s bound is passed in rather than read,
 * because a Domain entity reads no policy — so every case here is decidable
 * without a store, a clock or a configuration.
 */
final class SessionTest extends DomainTestCase
{
    private const REFERENCE = '0123456789abcdef0123456789abcdef';

    private const ESTABLISHED = '2026-08-20T12:00:00Z';

    /** Twenty-four hours, as `SEC-039` ‡ was decided on 2026-08-20. */
    private const LIFETIME = 86400;

    public function test_a_session_is_usable_within_its_bound(): void
    {
        $session = $this->established();

        self::assertTrue($session->isUsableAt(Instant::fromString('2026-08-21T11:59:59Z'), self::LIFETIME));
    }

    public function test_a_session_expires_when_the_bound_elapses(): void
    {
        // SEC-039 ‡, measured from establishment. The bound is the width of the
        // window, so a session established exactly a lifetime ago has expired —
        // the alternative would make the window one second wider than the figure.
        $session = $this->established();

        self::assertTrue($session->hasExpiredAt(Instant::fromString('2026-08-21T12:00:00Z'), self::LIFETIME));
        self::assertFalse($session->isUsableAt(Instant::fromString('2026-08-21T12:00:00Z'), self::LIFETIME));
    }

    public function test_a_shortened_bound_applies_to_a_session_already_established(): void
    {
        // Why CMP-IMP-051 stores no expires_at: BE-170 invalidates the policy
        // cache on change so no restart is required, and an expiry frozen at
        // establishment would make a shortened bound apply only to new sessions.
        $session = $this->established();
        $anHourLater = Instant::fromString('2026-08-20T13:00:00Z');

        self::assertFalse($session->hasExpiredAt($anHourLater, self::LIFETIME));
        self::assertTrue($session->hasExpiredAt($anHourLater, 600));
    }

    public function test_a_lifetime_of_zero_is_not_a_bound(): void
    {
        // Negative test for SEC-039 ‡: a bound of zero would expire every session
        // at the instant it was established, which is an absent lifetime rather
        // than a bounded one, and would look like the platform working.
        $this->expectException(InvalidArgumentException::class);

        $this->established()->hasExpiredAt(Instant::fromString(self::ESTABLISHED), 0);
    }

    public function test_a_terminated_session_is_recorded_and_unusable(): void
    {
        // SEC-040 ‡ / DB-044 ‡: recorded as terminated and not removed, so that
        // reuse is detectable rather than merely impossible.
        $session = $this->established();
        $session->terminate(Instant::fromString('2026-08-20T13:00:00Z'));

        self::assertTrue($session->isTerminated());
        self::assertNotNull($session->terminatedAt());
        self::assertFalse($session->isUsableAt(Instant::fromString('2026-08-20T13:00:01Z'), self::LIFETIME));
    }

    public function test_terminating_twice_keeps_the_first_instant(): void
    {
        // DB-044 ‡ retains the record so that reuse is detectable, and detection
        // needs the instant the session stopped being usable — not the instant
        // somebody asked again. Idempotent per API-062 ‡.
        $session = $this->established();
        $session->terminate(Instant::fromString('2026-08-20T13:00:00Z'));
        $session->terminate(Instant::fromString('2026-08-20T18:00:00Z'));

        self::assertSame('2026-08-20 13:00:00.000000', $session->terminatedAt()?->toDatabaseString());
    }

    public function test_a_terminated_session_stays_unusable_even_within_its_bound(): void
    {
        // The two conditions of SEC-048 ‡ are independent: an unexpired session
        // that was terminated is still refused, and a caller cannot tell which
        // of the two applied.
        $session = $this->established();
        $session->terminate(Instant::fromString('2026-08-20T12:00:01Z'));

        self::assertFalse($session->hasExpiredAt(Instant::fromString('2026-08-20T12:00:02Z'), self::LIFETIME));
        self::assertFalse($session->isUsableAt(Instant::fromString('2026-08-20T12:00:02Z'), self::LIFETIME));
    }

    public function test_a_session_holds_a_hash_and_never_a_token(): void
    {
        // SEC-036 ‡. Asserted structurally: an accessor returning a raw token
        // would be the way one reached a log or a response, which SEC-038 ‡
        // forbids.
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => strtolower($method->getName()),
            (new ReflectionClass(Session::class))->getMethods(),
        );

        self::assertContains('tokenhash', $methods);
        self::assertNotContains('token', $methods);
        self::assertNotContains('__tostring', $methods);
    }

    public function test_a_session_carries_no_authorisation_claim(): void
    {
        // SEC-045 ‡: entitlement is evaluated against platform state on every
        // request, so a session that could carry a claim is one that could carry
        // a stale one. Asserted as an absence of anywhere to put it.
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => strtolower($property->getName()),
            (new ReflectionClass(Session::class))->getProperties(),
        );

        self::assertSame(['user', 'tokenhash', 'establishedat', 'terminatedat'], $properties);
    }

    public function test_a_session_without_a_hash_is_refused(): void
    {
        // SEC-036 ‡: an empty hash would identify every session at once.
        $this->expectException(InvalidArgumentException::class);

        Session::establish(
            UserReference::fromString(self::REFERENCE),
            '  ',
            Instant::fromString(self::ESTABLISHED),
        );
    }

    private function established(): Session
    {
        return Session::establish(
            UserReference::fromString(self::REFERENCE),
            'a-token-hash',
            Instant::fromString(self::ESTABLISHED),
        );
    }
}
