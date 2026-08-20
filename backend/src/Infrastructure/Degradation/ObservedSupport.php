<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Degradation;

use Cmp\Application\Shared\Degradation\ObservesSupport;
use Cmp\Domain\Shared\Degradation\Kind;
use Cmp\Domain\Shared\Degradation\Support;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyStore;

/**
 * `FRD-FR-255`, step 1 — whether each kind of dependency is answering.
 *
 * ## A policy value
 *
 * Asked of {@see PolicyStore::isSet()}, which exists for this shape of question:
 * *"a caller that can act without the value asks first; a caller that cannot lets
 * `read()` raise."* This is the first kind of caller. `SRS-REQ-158` is what makes
 * the answer meaningful — an unset value is **rejected** at the point of use, so
 * a capability that reads one cannot work, and `FRD-FR-256` ‡ forbids presenting
 * it as though it could.
 *
 * A key the register does not declare answers **not answering** rather than
 * raising. `DB-153` ‡ makes an undeclared key unreadable, so a capability
 * depending on one can never work — which is exactly the condition being
 * reported, and raising here would take the health endpoint down with it.
 *
 * ## A supporting service
 *
 * **No supporting service is observable, and none is claimed to be.**
 * CMP-DOC-09 §12's port register holds five ports and `PortServiceProvider`
 * binds none: `BE-161` leaves three providers `[TBD – Business Decision
 * Required]`, and one — emergency dispatch — must never be bound at all
 * (`BAD-DEC-011`). An adapter is the only thing that could tell whether a
 * provider is answering, and there is no adapter.
 *
 * So a service dependency answers **false**, which reads as *"not answering"*.
 * That is the conservative direction and the one `SRS-REQ-113` requires:
 * synthesising *"it is probably up"* about a provider nobody has selected would
 * be reporting a capability as working on no evidence at all. `FRD-FR-258` ‡ says
 * the same — an unknown outcome is not resolved by assumption **in either
 * direction**, and availability is one of the two directions.
 *
 * The consequence is visible rather than hidden: any capability declared against
 * a supporting service is reported withdrawn or marked from the moment it is
 * registered, until an adapter exists to say otherwise. That is `NFR-034` ‡'s
 * defined degraded mode being the platform's actual state, which is what a
 * degraded mode is for.
 */
final class ObservedSupport implements ObservesSupport
{
    public function __construct(
        private readonly PolicyStore $policy,
        private readonly PolicyRegister $declared,
    ) {}

    public function isAnswering(Support $support): bool
    {
        return match ($support->kind()) {
            Kind::PolicyValue => $this->declared->declares($support->name())
                && $this->policy->isSet($this->declared->key($support->name())),

            // See the class note. No adapter exists to ask, and "probably up" is
            // not an observation.
            Kind::Service => false,
        };
    }
}
