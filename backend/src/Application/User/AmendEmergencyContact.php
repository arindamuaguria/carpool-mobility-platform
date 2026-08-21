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
 * `FRD-FR-182` / `FRD-FR-183` — amend a nominated contact.
 *
 * `PUT /profile/emergency-contacts/{id}`, which is the one write CMP-DOC-10
 * §11.2 does state. It replaces the details and keeps the nomination: the
 * reference is what makes an amendment an amendment rather than a removal
 * followed by a nomination, and `FRD-FR-024`'s principle applies — where the
 * amendment is rejected, the stored contact is unchanged and the caller is told
 * why.
 *
 * ## A reference that names nothing
 *
 * `SEC-069` ‡ and `API-094` ‡ require absence and non-entitlement to be
 * indistinguishable. They are here by construction: the set is loaded **for the
 * calling user** and the reference is resolved inside it, so a contact belonging
 * to somebody else and a contact belonging to nobody take the same path to the
 * same `emergency_contact.not_nominated`. There is no lookup that could have
 * told a caller which, so there is nothing to be careful about later.
 *
 * A **malformed** reference is different, and is an invalid request rather than
 * a refusal: the caller sent something that is not an identifier at all, and
 * `API-087` puts what the caller can correct in that branch. It discloses
 * nothing, because it is decided without asking the store anything.
 */
final class AmendEmergencyContact extends ApplicationService
{
    public const ACTION = 'emergency_contact.amended';

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
        return Operation::named('profile.emergency_contacts.amend');
    }

    protected function target(Command $command): ?AuthorisationTarget
    {
        return $command instanceof EmergencyContactCommand ? $command : null;
    }

    protected function handle(Command $command, Actor $actor): Result
    {
        if (! $command instanceof EmergencyContactCommand) {
            throw new LogicException(self::class.' amends a contact belonging to the calling user.');
        }

        $reference = ContactReferences::parse($command->reference());

        if ($reference instanceof InvalidRequest) {
            return Result::failed($reference);
        }

        $details = ContactDetails::from($command->number(), $command->label());
        $unusable = $details->unusable();

        if ($unusable !== null) {
            return Result::failed($unusable);
        }

        $this->transaction->transactional(function () use ($command, $reference, $details, $actor): void {
            $amended = $this->contacts
                ->forUser($command->user())
                ->amending($reference, $details->number(), $details->label());

            $this->contacts->save($amended);

            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor->reference()->toString()),
                self::ACTION,
                $reference->toString(),
                EvidentialOutcome::Succeeded,
                $this->clock->now(),
            ));
        });

        $contact = $this->contacts->forUser($command->user())->referenced($reference);

        if ($contact === null) {
            // The amendment committed, so the contact is there. Reaching here
            // would mean the store lost a write it acknowledged, which BE-186 ‡
            // keeps as an internal fault rather than dressing up as a refusal.
            throw new LogicException('FRD-FR-182: an amended contact is readable after its transaction commits.');
        }

        return Result::success(ContactView::of($contact));
    }
}
