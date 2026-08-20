<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Configuration;

use Cmp\Application\Shared\Response\EvaluationTime;
use Throwable;

/**
 * The configuration version, for a response that is not about configuration.
 *
 * `API-189`: *"Any response on any surface may indicate that configuration has
 * changed, so that the client refetches without polling."* `API-190` ‡ forbids the
 * client polling on an interval it chose, so without this marking a client has no
 * way of learning that a value moved — it would apply a stale bound until it
 * happened to restart.
 *
 * **This exists so the adapter does not have to read configuration**, in exactly
 * the way {@see EvaluationTime} exists so it does
 * not have to read a clock. `BE-002` keeps Domain types out of the `Interface`
 * layer, and the policy store is one.
 *
 * ## Why it returns null rather than raising
 *
 * `API-189` says a response **may** indicate the change; it is a marking on an
 * answer, never the answer. A marking that could fail would take every response
 * on the surface down with it — including `GET /health`, whose whole purpose is
 * to answer while the platform is degraded, and including the failure branches
 * `API-071` ‡ requires a caller to be able to read.
 *
 * So an unreadable configuration produces **no marking** rather than an error, and
 * the client behaves exactly as it does before its first fetch: it keeps the
 * conservative default `API-193` gives it. Nothing is synthesised, which is what
 * `SRS-REQ-113` forbids — an absent marking says nothing, where a made-up version
 * would say *"unchanged"* and be believed.
 */
final class ConfigurationVersion
{
    public function __construct(private readonly ServeConfiguration $configuration) {}

    /**
     * `API-188`'s version, or null where it cannot be produced.
     *
     * The public subset, because this reaches responses on the unauthenticated
     * surface too and `API-195` bounds what those may carry. A client that holds
     * more than the public subset compares against `GET /configuration`'s own
     * version, which is the same digest over the same set.
     */
    public function current(): ?string
    {
        try {
            return $this->configuration->public()->version();
        } catch (Throwable) {
            // Deliberately broad, and deliberately silent. See the class note:
            // this is a marking, and the alternative to omitting it is failing
            // an answer that had nothing to do with configuration. The condition
            // is not hidden — GET /health reports every unset value and every
            // capability it withdraws (FRD-FR-255, FRD-FR-257).
            return null;
        }
    }
}
