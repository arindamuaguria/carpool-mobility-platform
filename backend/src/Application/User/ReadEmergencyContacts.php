<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\Idempotency\IdempotentOperation;
use Cmp\Application\Shared\Result;
use Cmp\Domain\User\EmergencyContactRepository;
use LogicException;

/**
 * `GET /profile/emergency-contacts` — the set as the platform holds it.
 *
 * `UC-048` step 1. The read exists so that a user can see what they nominated
 * before amending it, and `FRD-FR-183`'s reason is only useful against a set the
 * caller can look at.
 *
 * ## Only the caller's own
 *
 * There is no path here by which a caller names whose set they mean: the user
 * comes from the session (`SEC-044` ‡ binds it to exactly one actor) and the
 * authorisation rule requires the caller to be the party. `SEC-066` ‡ needs
 * nothing more, and `API-129` ‡'s prohibition on disclosing contact details
 * beyond a qualifying relationship is met by there being no relationship under
 * which anybody else could ask.
 *
 * ## It changes nothing, and still carries a key
 *
 * `API-057` ‡ / `AADR-04` made the idempotency key mandatory on the group rather
 * than per operation, so this read carries one it does not need. That is the
 * decision working: a mandatory guarantee applied per route is one that lapses
 * on the route somebody adds in a hurry.
 */
final class ReadEmergencyContacts extends ApplicationService
{
    public function __construct(
        Authoriser $authoriser,
        IdempotentOperation $idempotency,
        private readonly EmergencyContactRepository $contacts,
    ) {
        parent::__construct($authoriser, $idempotency);
    }

    public function operation(): Operation
    {
        return Operation::named('profile.emergency_contacts.read');
    }

    protected function target(Command $command): ?AuthorisationTarget
    {
        return $command instanceof ReadContactsCommand ? $command : null;
    }

    protected function handle(Command $command, Actor $actor): Result
    {
        if (! $command instanceof ReadContactsCommand) {
            throw new LogicException(self::class.' reads the calling user\'s contacts.');
        }

        return Result::success(ContactSetView::of($this->contacts->forUser($command->user())));
    }
}
