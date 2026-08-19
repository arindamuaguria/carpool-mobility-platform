<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest;

/**
 * Which interface versions the platform serves, in one place.
 *
 * `API-019` ‡ puts the version in the first path segment as `/api/v{n}/`.
 * `API-021` requires the current version and the immediately preceding one to be
 * served **concurrently**, and `AADR-03` gives the reason: a client cannot be
 * required to update in step with the platform.
 *
 * ## One version is served, and that is not `API-021` being ignored
 *
 * `v1` is current. **There is no preceding version, because there has never been
 * one** — `API-021` obliges the platform to keep serving `N−1` once `N+1` exists,
 * and until a `v2` is issued there is nothing to keep serving. So
 * {@see deprecated()} is empty and {@see isDeprecated()} answers false for every
 * version, which is the truth rather than a stub.
 *
 * `VersioningTest` asserts that exactly one version is served. That is
 * deliberate: the day a `v2` is issued, the assertion fails and whoever issues it
 * has to come back here and to `API-023`'s marking rather than discovering later
 * that nothing was ever marked deprecated.
 *
 * `API-033` records that **how long** the preceding version is served is
 * `[TBD – Business Decision Required]`; it depends on a client update profile
 * nobody has measured. No duration is stated here, and none is needed to serve a
 * version.
 *
 * ## What this class is not
 *
 * It is not a feature switch. `API-031` is explicit that *"the domain shall not
 * be versioned; both served versions shall reach the same application services"*,
 * so nothing downstream of the adapter ever asks which version it is serving.
 * This decides one thing: whether to serve at all, and whether to mark the answer
 * deprecated.
 */
final class ServedVersions
{
    /**
     * `API-019` ‡: the first path segment, `/api/v{n}/`.
     */
    public const PREFIX = 'api';

    /**
     * The version now current.
     */
    public const CURRENT = 1;

    /**
     * The versions a request may name, newest first.
     *
     * `API-025`: the version-unsupported outcome states this, so a client that
     * has fallen behind learns what it may ask for without guessing.
     *
     * @return non-empty-list<int>
     */
    public static function all(): array
    {
        return [self::CURRENT, ...self::deprecated()];
    }

    /**
     * `API-021`'s `N−1`, and any earlier version still inside `API-032`'s notice
     * period.
     *
     * **Empty, and truthfully so.** A `v2` has not been issued, so there is no
     * preceding version to keep serving. When one is, `CURRENT` becomes 2 and 1
     * moves here, in the same change.
     *
     * @return list<int>
     */
    public static function deprecated(): array
    {
        return [];
    }

    /**
     * The immediately preceding version, or null where there is none.
     */
    public static function preceding(): ?int
    {
        return self::deprecated()[0] ?? null;
    }

    public static function supports(int $version): bool
    {
        return in_array($version, self::all(), true);
    }

    /**
     * `API-023`: a response served by the preceding version additionally
     * indicates that the version is deprecated.
     */
    public static function isDeprecated(int $version): bool
    {
        return in_array($version, self::deprecated(), true);
    }

    /**
     * `/api/v1` — the prefix a route is registered under.
     */
    public static function pathFor(int $version): string
    {
        return self::PREFIX.'/v'.$version;
    }
}
