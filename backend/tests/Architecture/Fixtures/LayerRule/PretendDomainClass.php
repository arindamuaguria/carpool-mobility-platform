<?php

declare(strict_types=1);

namespace Tests\Architecture\Fixtures\LayerRule;

use Illuminate\Support\Collection;

/**
 * A deliberate violation of `BE-002` and `BE-003`, used only by
 * LayerDependencyRuleTest to prove that the layer rule actually fires.
 *
 * This file is never autoloaded into the application. The fixture deptrac
 * configuration maps this directory to the Domain layer; the production
 * configuration does not include it.
 */
final class PretendDomainClass
{
    /** @return Collection<int, string> */
    public function reachOutward(): Collection
    {
        return new Collection(['a rule that fires']);
    }
}
