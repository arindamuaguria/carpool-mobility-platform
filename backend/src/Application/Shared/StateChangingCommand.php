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

    /**
     * A stable fingerprint of this command’s content.
     *
     * `API-062` ‡ distinguishes a repeat with the **same** key and the same
     * content — which returns the original outcome — from `API-063` ‡, a
     * repeat with the same key and different content, which is refused. The
     * registry cannot tell them apart without one.
     *
     * It is derived from the command’s own values, in application terms
     * (`BE-042`): a transport body would make the answer depend on how the
     * request happened to be serialised.
     */
    public function contentFingerprint(): string;
}
