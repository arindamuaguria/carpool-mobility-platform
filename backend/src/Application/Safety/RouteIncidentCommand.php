<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\StateChangingCommand;

/**
 * Mark one incident as having reached the operator queue.
 *
 * Built by the job, not by a request. `BE-140` carries the acting identity
 * explicitly on the job — a worker has no ambient session — and `BE-043` keeps
 * the service invocable from any caller, so the command carries only what the
 * work needs.
 *
 * It is not a {@see StateChangingCommand} even though it
 * changes state: the **job** is the state-changing command, and it holds the
 * idempotency key `BE-135` ‡ requires. A second key here would be a second
 * registry entry for one piece of work.
 */
final class RouteIncidentCommand implements Command
{
    public function __construct(private readonly string $reference) {}

    public function reference(): string
    {
        return $this->reference;
    }
}
