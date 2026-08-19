<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Failure;

use Cmp\Domain\Shared\Refusal\BusinessRefusal;
use Cmp\Domain\Shared\Refusal\RefusalKind;
use Cmp\Domain\Shared\Refusal\RefusalReason;

/**
 * A rule declined the operation. Retry will not help; the answer will not change.
 *
 * `API-081` ‡ a stable machine-readable reason identifier · `API-082` ‡ a
 * human-readable default suitable for presentation · `API-087` the kind
 * distinguishes a state conflict from a rule declining.
 *
 * `API-086` ‡ and `API-094` ‡ constrain the reason itself: absence and
 * non-entitlement are indistinguishable to a caller, so that existence cannot be
 * probed. That is a property of the reasons an area declares, and is verified
 * where those reasons are declared.
 *
 * `BE-186` ‡: never represented as an internal fault, and never the
 * representation of one. `BE-187` ‡: never the representation of a dependency
 * that did not answer — a refusal asserts that something was decided.
 */
final class BusinessRefused extends Failure
{
    public function __construct(private readonly RefusalReason $reason) {}

    public static function from(BusinessRefusal $refusal): self
    {
        return new self($refusal->reason());
    }

    public function branch(): FailureBranch
    {
        return FailureBranch::BusinessRefusal;
    }

    public function reason(): RefusalReason
    {
        return $this->reason;
    }

    /** The stable identifier the client keys its localised text by (`API-083`). */
    public function identifier(): string
    {
        return $this->reason->identifier();
    }

    /** The presentation default for an unrecognised identifier (`API-083`). */
    public function defaultText(): string
    {
        return $this->reason->defaultText();
    }

    public function kind(): RefusalKind
    {
        return $this->reason->kind();
    }
}
