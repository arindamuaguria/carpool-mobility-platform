<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Policy;

use Cmp\Domain\Shared\Policy\PolicyKey;
use RuntimeException;

/**
 * Two operators changed the same policy value at once, and this one lost.
 *
 * The version number is decided by the unique constraint on
 * `(policy_value_id, version)`, not by a read — the same technique `DB-142` ‡
 * uses for the idempotency registry, and for the same reason: *"so that a
 * duplicate is rejected by the database rather than by a race-prone read."*
 *
 * A locking read was the obvious alternative and is not available: the
 * application account holds `SELECT` and `INSERT` on `cfg_` and nothing more,
 * and MySQL refuses a locking read without `UPDATE`. That is the grant doing its
 * job — the same grant that makes `DB-152`'s *"never an update in place"* a
 * property of the credential.
 *
 * The loser is told rather than silently retried: the value it read as the
 * previous one is no longer the previous one, and `BE-173` records a change from
 * a specific value. Re-reading and re-applying is the operator's decision.
 */
final class PolicyChangeConflicted extends RuntimeException
{
    public static function forKey(PolicyKey $key): self
    {
        return new self(sprintf(
            'BE-173: "%s" was changed by someone else while this change was being applied. '
            .'Re-read the current value and apply the change against it.',
            $key->name(),
        ));
    }
}
