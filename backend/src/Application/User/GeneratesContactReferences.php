<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Domain\User\EmergencyContactReference;

/**
 * Where a new external identifier comes from.
 *
 * `DB-023` ‡ requires enough entropy that enumeration is infeasible, which needs
 * a cryptographically adequate random source. `BE-002` and `TC-029` ‡ keep the
 * Domain free of the environment, so {@see EmergencyContactReference} validates
 * a value and does not produce one — the same division {@see
 * \Cmp\Domain\Shared\Time\Clock} makes for the instant and {@see
 * HashesSessionTokens} for the token.
 *
 * The platform's **first** reference generator. `op_users.external_id` is
 * written by whoever registers a user, and registration is blocked on `CC-034`,
 * so nothing had needed one until now.
 */
interface GeneratesContactReferences
{
    public function next(): EmergencyContactReference;
}
