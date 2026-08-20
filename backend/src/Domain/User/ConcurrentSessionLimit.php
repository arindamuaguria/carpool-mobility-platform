<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

use InvalidArgumentException;

/**
 * How many sessions one user may hold at once, and what happens at the limit.
 *
 * `SEC-049`: *"Concurrent sessions per user shall be permitted, and their number
 * shall be policy configuration. **Decided 2026-08-20: three.**"*
 *
 * `SEC-243` ‡ states the behaviour: *"Where a user already holds the number of
 * concurrent sessions `SEC-049` permits, establishment shall be **refused** as a
 * business refusal carrying its own reason identifier, and shall be recorded. **No
 * existing session shall be terminated to make room.**"*
 *
 * ## Why the rule is here and the number is not
 *
 * `BE-010` puts a business rule in exactly one Domain component; `BE-012` puts an
 * absolute rule out of reach of override. The **rule** is that a fourth session is
 * refused — that is `SEC-243` ‡ and it is not configurable. The **number** is
 * `SEC-049`'s policy configuration, read by the application layer and passed in,
 * because `BE-002` keeps a Domain type from reading policy and `BE-172` ‡ keeps a
 * policy value from relaxing a rule.
 *
 * A value of three that could be raised to thirty does not relax `SEC-243` ‡: the
 * fourth-past-the-limit session is still refused, whatever the limit is. What no
 * configuration can produce is eviction, because there is no code that evicts.
 *
 * ## Why refusal rather than eviction, in one sentence
 *
 * `SEC-243` ‡'s own FACT records it: evicting the oldest session would let anyone
 * who obtained a demonstration silently displace a device the user still holds,
 * and a user who has lost a device is better served by a refusal they can see than
 * by a platform that quietly made room for the attacker.
 */
final class ConcurrentSessionLimit
{
    private function __construct(private readonly int $permitted) {}

    /**
     * @param  int  $permitted  `SEC-049`'s number, read from policy configuration
     */
    public static function of(int $permitted): self
    {
        if ($permitted < 1) {
            // SEC-049 says concurrent sessions "shall be permitted". A limit of
            // zero would permit none, which is not a bound on concurrency but a
            // prohibition of authenticated use — and BE-172 ‡ forbids a policy
            // value from reaching a place an absolute rule lives.
            throw new InvalidArgumentException(sprintf(
                'SEC-049: concurrent sessions are permitted, so the limit is at least one. %d would refuse '
                .'every establishment, which is not a limit but a closed platform.',
                $permitted,
            ));
        }

        return new self($permitted);
    }

    public function permitted(): int
    {
        return $this->permitted;
    }

    /**
     * `SEC-243` ‡: whether the user already holds as many as they may.
     *
     * Written as `>=` rather than `>`, so that the limit is the number a user may
     * **hold** rather than the number they may exceed by one. Three permitted
     * means a fourth is refused, which is what `SEC-049`'s figure means in plain
     * language.
     */
    public function reachedBy(int $sessionsHeld): bool
    {
        if ($sessionsHeld < 0) {
            throw new InvalidArgumentException('A count of sessions held is not negative.');
        }

        return $sessionsHeld >= $this->permitted;
    }
}
