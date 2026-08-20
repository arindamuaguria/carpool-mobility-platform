<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Controller;

use Cmp\Application\Shared\Degradation\ReportPlatformHealth;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Interface\Rest\Envelope;
use Cmp\Interface\Rest\ServedVersions;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v{n}/health` — `BE-203`, platform health distinguished from
 * dependencies.
 *
 * CMP-DOC-10 §11.13 catalogues it and §9.1 places it among the five operations
 * reachable **without a session**, citing `BE-203`. That placement is what makes
 * it useful: a client that cannot authenticate — because the capability that
 * authenticates is withdrawn — must still be able to find out why.
 *
 * ## What the body says, and why in this shape
 *
 * `FRD-FR-257` requires the affected actor to be told *"what is unavailable **and
 * what remains available**"*, and `API-091` requires the state propagated *"so
 * that the client can disclose what remains available"*. So `capabilities` lists
 * **every** declared capability with its standing — a client reads both halves
 * from one list rather than subtracting one from a set it must know separately.
 *
 * `platform` is `BE-203`'s distinction: a fact about the instance that answered,
 * separate from `missing`, which is about everything else. A caller that gets no
 * response at all is the third case, and no response body can convey it.
 *
 * ## What the body deliberately does not say
 *
 * **No provider is named**, and no error from one appears. `API-090` forbids it
 * on the dependency-unavailable branch and the reasoning is the same here, with
 * the addition that this response is reachable by anyone at all — `§9.1` puts it
 * outside the session. A dependency is named by what the platform calls it.
 *
 * **No count, latency, version, hostname or queue depth.** `BE-204`'s queue depth
 * and `BE-199`'s correlation belong to operational telemetry, which `SEC-114` and
 * `OPS-098` keep off an unauthenticated surface. What a client needs in order to
 * behave correctly is what is here.
 *
 * ## Why it is not behind `RequireIdempotencyKey`'s scope
 *
 * It is, in fact — the middleware is on the outer group. `API-057` ‡ applies to
 * **state-changing** operations, and `RequireIdempotencyKey` already exempts a
 * read, which is why `GET /versions` works without one.
 */
final class HealthController
{
    public function __construct(
        private readonly ReportPlatformHealth $health,
        private readonly EvaluationTime $evaluatedAt,
    ) {}

    public function __invoke(): JsonResponse
    {
        $health = $this->health->now();

        return Envelope::of(
            [
                // BE-203: the responding instance itself.
                'platform' => ['answering' => $health->platformIsAnswering()],

                // FRD-FR-257, both halves in one list.
                'capabilities' => $health->standingsDescribed(),

                // FRD-FR-255: what is not answering. Named in the platform's
                // terms; API-090's reasoning keeps every provider out of it.
                'missing' => $health->missingDescribed(),

                // FRD-FR-256 ‡ in one boolean, for a client whose only question
                // is whether to proceed. It is derived from the list above, so
                // the two cannot disagree.
                'fully_available' => $health->isFullyAvailable(),
            ],
            Envelope::meta(ServedVersions::CURRENT, $this->evaluatedAt->stamp()),
        );
    }
}
