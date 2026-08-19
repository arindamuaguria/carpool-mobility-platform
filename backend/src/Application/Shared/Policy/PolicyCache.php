<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Policy;

use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyStore;

/**
 * `BE-170`: the policy cache is invalidated on change, and **no restart is
 * required**.
 *
 * Separate from {@see PolicyStore} so that the domain's
 * view of policy is read-only. A domain component that could clear a cache could
 * also be made to clear it at the wrong moment.
 */
interface PolicyCache
{
    public function forget(PolicyKey $key): void;

    public function forgetAll(): void;
}
