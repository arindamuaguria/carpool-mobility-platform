<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Failure;

use Cmp\Application\Shared\CorrelationIdentity;

/**
 * The platform failed. Not the caller's concern; the platform investigates.
 *
 * `API-092` ‡ / `BE-189` ‡: an internal fault discloses **no internal detail** —
 * no stack, no query, no identifier of an internal component. This class
 * therefore holds nothing but a correlation identity. It does not accept a
 * throwable, does not retain a message, and has no accessor that could leak one:
 * the constraint is structural, not a convention to be remembered at the
 * interface layer.
 *
 * The cause is recorded against the same correlation identity by the
 * observability path (`BE-190`, `BE-199`), where it is reachable by the platform
 * and not by the caller.
 *
 * `API-093`: the correlation identity is what a caller may quote to support.
 * `BE-186` ‡: an internal fault is never represented as a business refusal.
 */
final class InternalFault extends Failure
{
    public function __construct(private readonly CorrelationIdentity $correlationIdentity) {}

    public function branch(): FailureBranch
    {
        return FailureBranch::InternalFault;
    }

    /** The only thing a caller is given (`API-093`). */
    public function correlationIdentity(): CorrelationIdentity
    {
        return $this->correlationIdentity;
    }
}
