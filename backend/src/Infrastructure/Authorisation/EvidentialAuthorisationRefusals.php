<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Authorisation;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationRefusalCause;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Authorisation\RecordsAuthorisationRefusals;
use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Domain\Shared\Time\Clock;
use Psr\Log\LoggerInterface;

/**
 * Writes a refused authorisation to the evidential log.
 *
 * **This discharges `SEC-057` ‡** — *"Every refused authorisation shall be
 * recorded"* — together with `BE-183`, `API-088` ‡, `ARCH-135` and `NFR-060`.
 * The interim implementation this replaces wrote only to the operational log and
 * said so; `BE-202` is explicit that operational logging *"shall not substitute
 * for"* the evidential log, and now it does not have to.
 *
 * ## What the record says
 *
 * `BE-107` ‡ fixes the six fields, and each has an obvious occupant here:
 *
 * - **actor** — the reference of whoever was refused, opaque by `DB-024` ‡.
 * - **action** — `authorisation.refused`. What occurred is the refusal itself.
 * - **subject** — the operation that was refused. It is an external name, not an
 *   internal key, so `DB-024` ‡ is satisfied without a lookup.
 * - **outcome** — `Refused`, which is the whole point of the record.
 * - **reason** — the **internal** cause, and this is deliberate. `SEC-069` ‡
 *   keeps absence and non-entitlement indistinguishable **to a caller**;
 *   `BE-182` requires the refusal to be a distinct outcome internally.
 *   {@see AuthorisationRefusalCause} is that distinction, and the evidential log
 *   is exactly the place it belongs — a refusal record that could not say why
 *   would be of little use to whoever reads it.
 * - **time** — from the {@see Clock}, so the record is testable.
 *
 * `BE-201` ‡ is satisfied by construction rather than by care: {@see Evidence}
 * has no free-form field, so there is nowhere for a payment credential, a
 * precise location or a contact detail to arrive.
 *
 * ## Ordering, and the transaction
 *
 * {@see Authoriser::authorise()} records
 * **before** it raises, so a refusal cannot be reported without its record
 * existing. This class keeps that property: if the evidential write fails,
 * `EvidenceNotRecorded` propagates in place of `AuthorisationRefused`, and the
 * caller receives an internal fault rather than a refusal the platform failed to
 * evidence. The operation is still not permitted, and `FRD-FR-248` ‡ holds — no
 * outcome is reported whose record could not be written.
 *
 * `BE-106` ‡ writes the record in the same transaction as the operation it
 * evidences. Authorisation is evaluated **before** the domain is invoked
 * (`BE-044`) and therefore before the application service opens a transaction,
 * so a refusal has no operation transaction to join and commits on its own. That
 * is the correct reading: there is no operation to be atomic with, because the
 * operation never ran.
 *
 * ## The log line as well, and why it is not the record
 *
 * `BE-200` wants operational records structured and searchable, and
 * `CMP-IMP-447` — the two audit views that would let an operator read the
 * evidential log — is blocked on `BAD-DEC-006`. Until it is not, a refusal is
 * reachable only by querying the table directly. The log line is written
 * **after** the evidential record, so one never exists for a refusal that was
 * not evidenced, and it carries `evidential => false` because `BE-202` requires
 * it to be distinct from and never a substitute for the record above it.
 */
final class EvidentialAuthorisationRefusals implements RecordsAuthorisationRefusals
{
    /**
     * `BE-107` ‡ — what occurred. One value, so a query for refusals is a query
     * for one action rather than a pattern nobody maintains.
     */
    public const ACTION = 'authorisation.refused';

    public function __construct(
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function record(Operation $operation, Actor $actor, AuthorisationRefusalCause $cause): void
    {
        // SEC-057 ‡. BE-114 ‡ requires the refusal reason, and Evidence refuses
        // a Refused record without one, so this cannot be written incomplete.
        $this->evidence->record(Evidence::of(
            $actor->reference(),
            self::ACTION,
            $operation->name(),
            EvidentialOutcome::Refused,
            $this->clock->now(),
            $cause->describe(),
        ));

        // BE-200 / BE-202: operational, distinct, and second — a line here means
        // the evidential record above it was written.
        $this->logger->warning(self::ACTION, [
            'operation' => $operation->name(),
            // DB-024 ‡: an opaque reference, never an internal key.
            'actor' => $actor->reference()->toString(),
            'cause' => $cause->name,
            'detail' => $cause->describe(),
            // BE-202: says plainly what this line is not.
            'evidential' => false,
        ]);
    }
}
