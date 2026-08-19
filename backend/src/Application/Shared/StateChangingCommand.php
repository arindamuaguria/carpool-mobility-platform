<?php

declare(strict_types=1);

namespace Cmp\Application\Shared;

use Cmp\Application\Shared\Idempotency\IdempotencyKey;

/**
 * A command that changes state, and therefore carries an idempotency key.
 *
 * `BE-045` / `API-057` ‡: an application service accepts an idempotency key for
 * every state-changing operation. Declaring it on the command type rather than
 * as a service parameter makes the obligation structural: a state-changing
 * command cannot be constructed without one.
 *
 * `API-065`: a read carries no key, and so implements {@see Command} instead.
 */
interface StateChangingCommand extends Command
{
    public function idempotencyKey(): IdempotencyKey;
}
