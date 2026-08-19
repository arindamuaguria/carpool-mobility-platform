<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Port;

use Cmp\Application\Shared\Failure\DependencyUnavailable;
use Cmp\Domain\Shared\Port\CapabilityResult;
use LogicException;

/**
 * Turns an unavailable capability into the failure branch that decides nothing.
 *
 * `BE-152` ‡: an application service treats `Unavailable` as a **distinct case**
 * and never as success or as failure. `API-089` ‡ says the same at the
 * interface: the branch conveys that nothing was decided.
 * `FRD-FR-258` ‡: an unknown outcome is not resolved by assumption in either
 * direction while a supporting service is unavailable.
 *
 * **Only `Unavailable` is mapped.** `Verified`, `Reported` and `Rejected` are
 * refused here on purpose. `BE-156` ‡ reserves the business outcome for the
 * domain: a helper that quietly turned a provider's rejection into a business
 * refusal would be an adapter deciding one, in the application layer, for every
 * port at once.
 */
final class UnavailableCapability
{
    /**
     * @throws LogicException where the result is not `Unavailable`
     */
    public static function asFailure(CapabilityResult $result): DependencyUnavailable
    {
        if (! $result->isUnavailable()) {
            throw new LogicException(sprintf(
                'BE-156 ‡: a %s result is the domain\'s to interpret, not this mapper\'s. '
                .'Only Unavailable maps to a failure branch, because only Unavailable decides nothing.',
                $result->outcome()->name,
            ));
        }

        return DependencyUnavailable::ofCapability($result->unavailableCapability());
    }
}
