<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest;

use Illuminate\Http\JsonResponse;

/**
 * The one shape every response on this surface has.
 *
 * Four statements meet here and none of them says **where** their marking goes:
 *
 * - `API-022` — every response states the version that served it;
 * - `API-023` — a response from the preceding version says it is deprecated;
 * - `API-043` ‡ — every response carries the time the platform evaluated it;
 * - `API-064` — a replayed response is indicated as a replay.
 *
 * They have to go somewhere, and putting each in a header of its own invention
 * would be four inventions instead of one. `meta` is that one: a single object
 * carrying what the platform says **about** the answer, beside `data` carrying the
 * answer. `API-046` anticipates exactly this — *"a client shall tolerate response
 * fields it does not recognise, so that additive change remains non-breaking"* —
 * and `API-029` makes adding a `meta` field non-breaking within a version.
 *
 * **This is an implementation choice, not a specified one**, and it is reported
 * as such. What is specified is that the four markings exist; that they share an
 * envelope is this file's decision, taken because the alternative was four.
 *
 * `API-035` is unaffected: a resource still has exactly one representation, and
 * `data` carries it whole. The envelope describes the response, not the resource.
 *
 * ## `API-072` ‡ and the failure shapes
 *
 * Failures use the same `meta` and **do not** use `data`. Each branch carries its
 * own top-level key instead, so the four remain *"distinguishable by structure
 * alone"* — see {@see FailureResponse}. A caller can tell the branch without
 * reading a discriminator field, which is what `API-072` ‡ asks for and what a
 * shared `error` object would have quietly given up.
 */
final class Envelope
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function of(array $data, array $meta, int $status = 200): JsonResponse
    {
        return new JsonResponse(['meta' => $meta, 'data' => $data], $status);
    }

    /**
     * The markings that describe the response itself.
     *
     * `evaluatedAt` is `API-043` ‡; the version pair is `API-022` and `API-023`.
     *
     * @return array<string, mixed>
     */
    public static function meta(int $version, string $evaluatedAt, ?string $configurationVersion = null): array
    {
        return [
            // API-189: any response on any surface may indicate that
            // configuration has changed, so the client refetches without
            // polling — which API-190 ‡ forbids it doing on its own interval.
            //
            // Present and null where the platform could not produce one, rather
            // than absent: a client can tell "the platform did not say" from
            // "the platform says nothing changed", and SRS-REQ-113 forbids the
            // second being invented. ConfigurationVersion says why it may be
            // null.
            'configuration_version' => $configurationVersion,
            // API-022: the version that served it, on every response.
            'interface_version' => $version,
            // API-023: only the preceding version is deprecated, and it says so
            // on every response rather than once at some negotiation step the
            // client might miss.
            'deprecated' => ServedVersions::isDeprecated($version),
            // API-043 ‡ / AADR-09: when the platform evaluated this.
            'evaluated_at' => $evaluatedAt,
        ];
    }
}
