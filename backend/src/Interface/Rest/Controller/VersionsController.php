<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Controller;

use Cmp\Application\Shared\Configuration\ConfigurationVersion;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Interface\Rest\Envelope;
use Cmp\Interface\Rest\ServedVersions;
use Illuminate\Http\JsonResponse;

/**
 * `CMP-IMP-462` — `GET /api/v{n}/versions`.
 *
 * `API-027`: *"The client shall be able to determine the supported range without
 * attempting a business operation."* Without this, a client learns it is
 * unsupported only by trying something and failing, which `MOB-057` cannot build
 * a startup check on.
 *
 * `API-026` and §9.1 place it among the five operations reachable **without a
 * session**, for the reason §9.1 gives: *"a client must learn it is unsupported
 * before it can authenticate."*
 *
 * ## The one operation that does not invoke an application service
 *
 * `API-002` ‡ requires every operation to invoke exactly one application service.
 * This one invokes none, and that is not an exemption being taken quietly: the
 * supported version range is a **property of the interface**, not of the
 * platform. `API-031` is explicit that the domain is not versioned, so there is
 * no application service that could know the answer, and inventing one would be
 * inventing a domain concept to satisfy a rule about adapters.
 *
 * It is also the only operation that can be answered without one. `BE-203`'s
 * health indication and §14's configuration resource both have platform state
 * behind them; this has a constant.
 *
 * Reported as an implementation reading of `API-002` ‡ rather than resolved
 * silently.
 */
final class VersionsController
{
    public function __construct(
        private readonly EvaluationTime $evaluatedAt,
        private readonly ConfigurationVersion $configurationVersion,
    ) {}

    public function __invoke(): JsonResponse
    {
        return Envelope::of(
            [
                // API-025 / API-027: the range, so a client need not probe for it.
                'supported' => ServedVersions::all(),
                'current' => ServedVersions::CURRENT,
                // API-021: null until a v2 exists. Stated rather than omitted, so
                // a client can tell "there is no preceding version" from "the
                // platform did not say".
                'preceding' => ServedVersions::preceding(),
            ],
            Envelope::meta(ServedVersions::CURRENT, $this->evaluatedAt->stamp(), $this->configurationVersion->current()),
        );
    }
}
