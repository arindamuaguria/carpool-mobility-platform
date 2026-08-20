<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Degradation;

/**
 * The two kinds of thing a capability can depend on ({@see Support}).
 *
 * Kept distinct rather than collapsed into one name, because `FRD-FR-257` tells
 * the affected actor **what** is unavailable and the two mean different things to
 * whoever reads it: a service that is not answering may answer in a minute, and a
 * value nobody has configured will not set itself.
 *
 * `BE-203` draws the same line one level up — *"health indication distinguishing
 * platform from dependencies"* — and an unset policy value is the platform's own
 * condition rather than a dependency's.
 */
enum Kind: string
{
    /** `FRD-FR-255` / `UC-081` `ACT-11`–`ACT-14`: an external supporting service. */
    case Service = 'SERVICE';

    /** `SRS-REQ-158`: a declared policy value that has not been set. */
    case PolicyValue = 'POLICY_VALUE';
}
