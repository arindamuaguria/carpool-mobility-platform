<?php

declare(strict_types=1);

namespace Cmp\Interface\Safety;

/**
 * Where the safety surface answers, and why it is not `/api/`.
 *
 * `API-163` ‡: *"Safety operations shall be served under a path prefix distinct
 * from the general surface."* `AADR-11` gives the reason in full, and it is an
 * operational one rather than an architectural preference:
 *
 * > *"If safety shares a path prefix with everything else, a gateway rule or a
 * > rate limiter written for the general case will eventually apply to it."*
 *
 * `BE-196` ‡ and `API-168` ‡ forbid a safety operation being rate-limited to the
 * point of refusal, and `OPS-046` exempts safety traffic from every rate limit,
 * quota and throttle. A distinct prefix is what lets a gateway honour that
 * without reading the application's mind — `API-RISK-05` names the failure it
 * prevents, and CMP-DOC-19 carries the obligation to route the prefix
 * independently.
 *
 * ## Separately bootable, whether or not it is separately booted
 *
 * `BE-191` ‡ makes the surface bootable as a separate entry point sharing the
 * same code, and `BADR-16` chose that over a separate service **because
 * duplication is the one thing the capability that must never be wrong cannot
 * afford**. `BE-198` settles the rest: *"Whether the separate entry point is
 * deployed at launch is a deployment decision for CMP-DOC-19; the code
 * separation is not conditional on it."*
 *
 * So `routes/safety.php` exists as its own file, registering its own group, and
 * `API-170` ‡ is met — a deployment serving only these operations needs no code
 * change to do it.
 */
final class SafetySurface
{
    /**
     * `/safety/v1/…` — CMP-DOC-10 §12.1.
     */
    public const PREFIX = 'safety';

    /**
     * The interface version this surface serves.
     *
     * The **same** number as the general surface, deliberately. CMP-DOC-10 §12
     * says the safety surface *"answers on the same contract"*, and `API-066` ‡
     * applies idempotency to it *"exactly as to the general surface"* — a second
     * version line would be a second contract to keep in step, for the one
     * capability that must never be wrong.
     */
    public const CURRENT = 1;

    public static function path(string $operation): string
    {
        return self::PREFIX.'/v'.self::CURRENT.'/'.ltrim($operation, '/');
    }
}
