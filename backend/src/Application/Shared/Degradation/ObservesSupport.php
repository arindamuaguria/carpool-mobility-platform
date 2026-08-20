<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Degradation;

use Cmp\Domain\Shared\Degradation\Support;

/**
 * Answers whether one thing a capability depends on is currently answering.
 *
 * `FRD-FR-255`, step 1 of `UC-081`: *"The platform detects that a supporting
 * service is unavailable."* This is the detection, behind a contract, so that
 * detecting it for a policy value and detecting it for an external service are
 * the same question to {@see ReportPlatformHealth}.
 *
 * ## It reports; it does not conclude
 *
 * `BE-156` ‡ says that of an adapter and it holds here: an implementation says
 * whether the thing answered. Which capabilities that affects is
 * `CapabilityDependence`'s, and what the platform then offers is
 * `CapabilityStanding`'s. Three questions, three places, and none of them
 * answerable from the others.
 *
 * ## Why it is not one of CMP-DOC-09 §12's ports
 *
 * `BE-149` declares a port for a **capability the platform does not itself
 * provide** — the platform asks a provider to do something. This asks nothing of
 * anybody: it establishes whether an answer would be possible. CMP-DOC-09 §12's
 * register holds five ports and this is not a sixth; adding one would be
 * declaring a capability nobody specified, which is what `CC-034` is open about.
 */
interface ObservesSupport
{
    /**
     * Whether this dependency is currently able to answer.
     *
     * `SRS-REQ-113` forbids synthesising a result, so an implementation that
     * cannot tell answers **false** rather than guessing true: `FRD-FR-256` ‡
     * would rather a working capability be marked than a broken one be presented
     * as working, and `FRD-FR-258` ‡ forbids resolving the unknown by assumption
     * in either direction — of which reporting availability is one direction.
     */
    public function isAnswering(Support $support): bool;
}
