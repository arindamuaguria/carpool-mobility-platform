<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Middleware;

use Closure;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Interface\Rest\ServedVersions;
use Cmp\Interface\Rest\UnsupportedVersion;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request naming a version the platform does not serve.
 *
 * `API-024` ‡ / `API-025` / `API-026`. Registered **first** in the group, because
 * `API-026` requires the version-unsupported outcome to be reachable without an
 * authenticated session: a client too old to be served is also a client that
 * cannot be expected to hold a valid session, and asking it for one before
 * telling it to update would be a loop.
 *
 * ## Why this reads the path rather than a route parameter
 *
 * `API-019` ‡ puts the version in the first path segment. A route registered for
 * `/api/v1/…` alone would never match `/api/v3/…`, and Laravel would answer `404`
 * — precisely the outcome `API-024` ‡ forbids. So the group is registered under a
 * `{version}` segment and this decides, which is also why the segment pattern
 * accepts any `v{n}` rather than only the served ones.
 *
 * `BE-005`: an adapter, holding no rule. What it knows is which versions are
 * served, which {@see ServedVersions} states and this does not restate.
 */
final class RequireSupportedVersion
{
    public function __construct(private readonly EvaluationTime $evaluatedAt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requested = self::versionIn($request);

        if ($requested === null || ! ServedVersions::supports($requested)) {
            return UnsupportedVersion::response($requested, $this->evaluatedAt->stamp());
        }

        return $next($request);
    }

    /**
     * The `{n}` of `/api/v{n}/…`, or null where the segment is not a version at
     * all — which is itself unsupported rather than not-found.
     */
    private static function versionIn(Request $request): ?int
    {
        $segment = $request->route('version');

        if (! is_string($segment) || preg_match('/^v(\d+)$/', $segment, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
