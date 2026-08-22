<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Domain\Safety\SafetyIncident;

/**
 * An incident, as the authorisation evaluation sees it.
 *
 * `BE-181` ‡ / `SEC-056` ‡: *"Ownership and relationship shall be evaluated
 * against platform state, never against an inbound claim."* So the read loads
 * the record and wraps it here, rather than passing the identifier the caller
 * sent — a caller who could name the parties to a record could name themselves
 * among them.
 *
 * The one party is whoever raised it. There is no second: an incident concerns
 * co-travellers whom `FRD-FR-186` ‡ has the platform capture, and being named in
 * somebody's safety incident does not entitle you to read it — `BAD-DEC-022` has
 * not decided what a counterparty may see of one, and this is the last record on
 * the platform where a widened default would be acceptable.
 */
final class RaisedIncident implements AuthorisationTarget
{
    public function __construct(private readonly SafetyIncident $incident) {}

    public function incident(): SafetyIncident
    {
        return $this->incident;
    }

    /**
     * @return list<ActorReference>
     */
    public function partyReferences(): array
    {
        return [ActorReference::fromString($this->incident->raisedBy()->toString())];
    }

    /**
     * `SEC-058` ‡: reading an incident alters nobody's entitlement.
     */
    public function entitlementSubject(): ?ActorReference
    {
        return null;
    }
}
