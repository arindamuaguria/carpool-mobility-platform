<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\EmergencyContactRepository;
use LogicException;

/**
 * `FRD-FR-182` — remove a nominated contact.
 *
 * `DELETE /profile/emergency-contacts/{id}`.
 *
 * ## The row goes, and that is the right treatment here
 *
 * `DADR-12` removes personal data **in place** rather than by cascade, and
 * `DB-044` ‡ keeps a terminated session rather than deleting it so that reuse is
 * detectable. Neither argues for keeping a withdrawn nomination. The data is a
 * **third party's**, held under `UC-048`'s explicit condition that the platform
 * has not yet defined what it is for; a user withdrawing it is the one act that
 * needs no retention decision to justify, and `BAD-DEC-021` having decided none
 * is a reason to hold less rather than more.
 *
 * What survives is the **evidential record** that the removal happened —
 * `UC-048` step 4 and `UC-079` — and it names the reference, never the number.
 * `BE-201` ‡: an evidential log that kept the number would make removal a
 * pretence.
 */
final class RemoveEmergencyContact extends ApplicationService
{
    public const ACTION = 'emergency_contact.removed';

    public function __construct(
        Authoriser $authoriser,
        private readonly TransactionBoundary $transaction,
        private readonly EmergencyContactRepository $contacts,
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
    ) {
        parent::__construct($authoriser);
    }

    public function operation(): Operation
    {
        return Operation::named('profile.emergency_contacts.remove');
    }

    protected function target(Command $command): ?AuthorisationTarget
    {
        return $command instanceof EmergencyContactCommand ? $command : null;
    }

    protected function handle(Command $command, Actor $actor): Result
    {
        if (! $command instanceof EmergencyContactCommand) {
            throw new LogicException(self::class.' removes a contact belonging to the calling user.');
        }

        $reference = ContactReferences::parse($command->reference());

        if ($reference instanceof InvalidRequest) {
            return Result::failed($reference);
        }

        $this->transaction->transactional(function () use ($command, $reference, $actor): void {
            // `without()` refuses where the reference names nothing in this
            // user's set — SEC-069 ‡'s indistinguishability, by construction.
            $this->contacts->save($this->contacts->forUser($command->user())->without($reference));

            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor->reference()->toString()),
                self::ACTION,
                $reference->toString(),
                EvidentialOutcome::Succeeded,
                $this->clock->now(),
            ));
        });

        return Result::succeeded();
    }
}
