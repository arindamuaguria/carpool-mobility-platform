<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Domain\User\UserReference;

/**
 * Read one's own emergency contacts — `UC-048` step 1.
 *
 * Split out of {@see EmergencyContactCommand}, which is a
 * {@see StateChangingCommand}. That was harmless while an idempotency key did
 * nothing; `CC-045` gave the key an effect, and the read began claiming a
 * registry entry and replaying itself.
 *
 * `API-065` / `API-007`: a safe method changes no authoritative state and
 * carries no key. There is nothing to replay, nothing to record, and no key to
 * scope by — so the read is a plain {@see Command} and
 * `ApplicationService::execute()` passes it straight through.
 *
 * It is still an {@see AuthorisationTarget}: `SEC-066` ‡ applies to a read as
 * much as to a write, and the party is the caller.
 */
final class ReadContactsCommand implements AuthorisationTarget, Command
{
    private function __construct(private readonly UserReference $user) {}

    public static function from(AuthenticatedCaller $caller): self
    {
        return new self($caller->session()->user());
    }

    public function user(): UserReference
    {
        return $this->user;
    }

    /**
     * `SEC-066` ‡ / `BE-181` ‡: the party is the user the session is bound to,
     * read from platform state.
     *
     * @return list<ActorReference>
     */
    public function partyReferences(): array
    {
        return [ActorReference::fromString($this->user->toString())];
    }

    /**
     * `SEC-058` ‡: reading alters nobody's entitlement.
     */
    public function entitlementSubject(): ?ActorReference
    {
        return null;
    }
}
