<?php

declare(strict_types=1);

namespace Cmp\Application\Safety;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\Result;
use Cmp\Domain\Safety\IncidentReference;
use Cmp\Domain\Safety\SafetyIncidentRepository;
use InvalidArgumentException;
use LogicException;

/**
 * `GET /safety/v1/incidents/{id}` — the raiser reads their own record.
 *
 * ## Absence and non-entitlement are the same answer, by construction
 *
 * `SEC-069` ‡ and `API-094` ‡ require them indistinguishable. Nothing here
 * compares a raiser to a caller and chooses a message: {@see target()} returns
 * the incident where one exists and **null** where it does not, and a rule
 * requiring the caller to be a party refuses a null target for the reason
 * `ApplicationService` states — *"an unverifiable requirement is not a met
 * one."*
 *
 * So an incident somebody else raised, an incident that never existed, and a
 * reference that is not an identifier at all take the same path to
 * `access.not_available_to_you`. There is no branch that could be got wrong,
 * which on this surface matters more than anywhere: the list of people who have
 * raised a safety signal is the most sensitive thing the platform holds, and a
 * distinguishable *"no such incident"* would be a way to test membership of it.
 *
 * ## No paging, no filtering, no list
 *
 * CMP-DOC-10 §12.1 specifies one read, by identifier. §12.3 omits paging and
 * filtering — *"not needed to record"* — and `BE-192` ‡ keeps the safety surface
 * to the minimum set of components. A collection endpoint would be a second way
 * to enumerate incidents, and `DB-023` ‡ went to some trouble to ensure there is
 * not a first.
 */
final class ReadOwnIncident extends ApplicationService
{
    public function __construct(
        Authoriser $authoriser,
        private readonly SafetyIncidentRepository $incidents,
    ) {
        parent::__construct($authoriser);
    }

    public function operation(): Operation
    {
        return Operation::named('safety.incidents.read');
    }

    protected function target(Command $command): ?AuthorisationTarget
    {
        if (! $command instanceof ReadOwnIncidentCommand) {
            return null;
        }

        try {
            $reference = IncidentReference::fromString($command->reference());
        } catch (InvalidArgumentException) {
            // Not an identifier. Deliberately the same answer as an identifier
            // naming nothing — see the class note.
            return null;
        }

        $incident = $this->incidents->forReference($reference);

        return $incident === null ? null : new RaisedIncident($incident);
    }

    protected function handle(Command $command, Actor $actor): Result
    {
        if (! $command instanceof ReadOwnIncidentCommand) {
            throw new LogicException(self::class.' reads an incident the calling user raised.');
        }

        // Authorisation has already run and has already loaded this — reaching
        // here means the caller is the raiser, so the reference parses and the
        // record exists.
        $incident = $this->incidents->forReference(IncidentReference::fromString($command->reference()));

        if ($incident === null) {
            throw new LogicException(
                'SEC-069 ‡: the authorisation evaluation loaded this incident, so it exists.'
            );
        }

        return Result::success(IncidentView::of($incident));
    }
}
