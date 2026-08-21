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
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\EmergencyContact;
use Cmp\Domain\User\EmergencyContactRepository;
use LogicException;

/**
 * `FRD-FR-181` / `FRD-FR-184` — nominate an emergency contact.
 *
 * `FRD-FR-181` is Specified, Mandatory, R1. **CMP-DOC-10 §11.2 states no
 * operation that creates one** — it lists `GET`, `PUT /{id}` and
 * `DELETE /{id}`, and attaches `FRD-FR-181` to the `GET`, which creates nothing.
 * `DB-022` ‡ closes the obvious alternative: every exposed entity carries a
 * **randomly generated** external identifier, so `PUT /{id}` cannot be a
 * create-at-a-caller-supplied-identifier either.
 *
 * The source-of-truth hierarchy decides it. CMP-DOC-04 governs over CMP-DOC-10,
 * `FRD-FR-181` is not withdrawn by a missing row, and the platform's own
 * convention supplies the shape without inventing one — `POST /vehicles`
 * registers a vehicle, `POST /rides` publishes a ride, and `POST` on the
 * collection is how every other creatable resource here is created. The conflict
 * is reported rather than quietly resolved: `CC-044`.
 *
 * ## What is recorded, and what is not
 *
 * `UC-048` step 4 records the event (`UC-079`), so the nomination is evidenced —
 * `BE-106` ‡ inside the transaction that carries it, `FRD-FR-248` ‡ so that a
 * nomination which could not be evidenced does not stand.
 *
 * `BE-201` ‡ is why the record names the **contact reference** and not the
 * number: the evidential log would otherwise hold a third party's phone number,
 * for a person who is not a user, has agreed to nothing, and whom `UC-OQ-006`
 * records the platform may never even tell. The subject identifies which
 * nomination, which is what `BE-107` ‡ needs; the number stays in the
 * operational table the user can amend and remove.
 *
 * ## Nothing is sent
 *
 * `FRD-GAP-020` / `BAD-DEC-011`. `UC-048` accepts holding a contact *"only
 * because nothing is sent to them"*, and this service sends nothing: no
 * notification, no confirmation to the nominee, no channel of any kind. `CC-034`
 * records that no delivery channel exists to send through even if one were
 * specified.
 */
final class NominateEmergencyContact extends ApplicationService
{
    public const ACTION = 'emergency_contact.nominated';

    public function __construct(
        Authoriser $authoriser,
        private readonly TransactionBoundary $transaction,
        private readonly EmergencyContactRepository $contacts,
        private readonly GeneratesContactReferences $references,
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
    ) {
        parent::__construct($authoriser);
    }

    public function operation(): Operation
    {
        return Operation::named('profile.emergency_contacts.nominate');
    }

    protected function target(Command $command): ?AuthorisationTarget
    {
        return $command instanceof EmergencyContactCommand ? $command : null;
    }

    protected function handle(Command $command, Actor $actor): Result
    {
        if (! $command instanceof EmergencyContactCommand) {
            throw new LogicException(self::class.' nominates a contact for the calling user.');
        }

        $details = ContactDetails::from($command->number(), $command->label());
        $unusable = $details->unusable();

        if ($unusable !== null) {
            // FRD-FR-183 / API-131. Nothing is written and nothing is evidenced:
            // the platform did not refuse a nomination, it never received one it
            // could record.
            return Result::failed($unusable);
        }

        $contact = EmergencyContact::of($this->references->next(), $details->number(), $details->label());

        $this->transaction->transactional(function () use ($command, $contact, $actor): void {
            // UC-048 A1's set rule raises where the number is already nominated,
            // before anything is written. The unique constraint is still what
            // decides a race (DB-142 ‡) — this is what gives the ordinary case a
            // reason the caller can read.
            $this->contacts->save($this->contacts->forUser($command->user())->with($contact));

            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor->reference()->toString()),
                self::ACTION,
                $contact->reference()->toString(),
                EvidentialOutcome::Succeeded,
                $this->clock->now(),
            ));
        });

        return Result::success(ContactView::of($contact));
    }
}
