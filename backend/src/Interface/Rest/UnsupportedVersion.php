<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest;

use Cmp\Interface\Rest\Middleware\RequireSupportedVersion;
use Illuminate\Http\JsonResponse;

/**
 * The outcome for a version the platform does not serve.
 *
 * `API-024` ‡ is unusually specific about what this must **not** be: *"a request
 * for a version that is neither current nor preceding shall receive a distinct
 * version-unsupported outcome, and shall **not** receive a not-found outcome."*
 * A `404` would tell a client on an old build that the operation had gone away,
 * and `MOB-057` needs it to enter a version-unsupported state instead — which it
 * cannot do if the platform says the path does not exist.
 *
 * CMP-DOC-10 §8.6 places it outside the four branches: *"Interface version
 * unsupported — **its own outcome**"*. So it carries neither `refusal` nor
 * `invalid_request`, and a client matching on those four keys will not mistake it
 * for one.
 *
 * **`426` is this file's choice.** No status is stated for it — §8.1's table
 * covers the four branches and §5 states behaviour without a code. `426 Upgrade
 * Required` is the one status whose defined meaning is *"the client should switch
 * to a different protocol version"*, and it is not a `404`, which is the
 * constraint `API-024` ‡ actually imposes. Reported as an implementation choice.
 *
 * `API-025`: the outcome states the versions the platform does serve.
 * `API-026`: it is reachable without an authenticated session — so this runs
 * before any session middleware, and {@see RequireSupportedVersion}
 * is registered first for that reason.
 */
final class UnsupportedVersion
{
    /**
     * The top-level key, distinct from all four failure branches.
     */
    public const KEY = 'unsupported_version';

    /**
     * `426 Upgrade Required` — not a `404` (`API-024` ‡).
     */
    public const STATUS = 426;

    public static function response(?int $requested, string $evaluatedAt): JsonResponse
    {
        return new JsonResponse([
            // The meta carries the version that served the refusal, which is the
            // current one — the request named a version nobody serves, so no
            // served version can be attributed to it.
            'meta' => Envelope::meta(ServedVersions::CURRENT, $evaluatedAt),
            self::KEY => [
                'requested' => $requested,
                // API-025: what the platform does serve, so the client is not
                // left to probe for it.
                'supported' => ServedVersions::all(),
                'default_text' => 'This version of the application is no longer supported. Please update.',
            ],
        ], self::STATUS);
    }
}
