<?php

declare(strict_types=1);

namespace Cmp\Interface\Rest\Controller;

use Cmp\Application\Shared\Configuration\ServeConfiguration;
use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Interface\Rest\Envelope;
use Cmp\Interface\Rest\ServedVersions;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v{n}/configuration` — CMP-DOC-10 §14.1.
 *
 * `MOB-123` and `MADR-11`: the client holds no policy value of its own, and a
 * change reaches it without a release (`API-196`). §9.1 places the **public
 * subset** among the five operations reachable without a session, for the reason
 * `API-193` gives — *"a cold client needs conservative defaults"*, and it needs
 * to replace them before it can do anything else.
 *
 * ## The public subset, and only that
 *
 * This serves {@see ServeConfiguration::public()} and never `all()`. `API-195`
 * keeps a value that discloses platform state out of the unauthenticated subset,
 * and the two methods are distinct so that the day a non-public value is added,
 * this route continues to serve the subset without anybody having to remember to
 * filter it.
 *
 * A session-bearing variant does not exist because there is nothing yet for it to
 * add: every entry in the register is public. It arrives with the first entry
 * that is not.
 *
 * ## `API-188` and `API-189`
 *
 * The version the response carries is in `meta.configuration_version`, which
 * **every** response on this surface carries — that is `API-189`, *"any response
 * on any surface may indicate that configuration has changed, so that the client
 * refetches without polling"*, and it is what makes `API-190` ‡'s prohibition on
 * polling something a client can actually obey.
 *
 * Per-value versions are in `data` beside the values, because `BE-167` requires a
 * decision to record the version it used and one response-level number could
 * never support that.
 */
final class ConfigurationController
{
    public function __construct(
        private readonly ServeConfiguration $configuration,
        private readonly EvaluationTime $evaluatedAt,
    ) {}

    public function __invoke(): JsonResponse
    {
        $served = $this->configuration->public();

        return Envelope::of(
            [
                // API-187 ‡: everything the client needs, in the declared type
                // (API-192). A value the operator has not set is absent, and
                // API-193's conservative default stands until it is.
                'values' => (object) $served->values(),

                // BE-167: the version each value came from.
                // Cast, so that an empty set serialises as {} and not as []. A
                // client typing this field against a map would meet an array the
                // day every value happened to be unset — API-046 has it tolerate
                // fields it does not recognise, not a field that changes type.
                'versions' => (object) $served->versions(),
            ],
            Envelope::meta(ServedVersions::CURRENT, $this->evaluatedAt->stamp(), $served->version()),
        );
    }
}
