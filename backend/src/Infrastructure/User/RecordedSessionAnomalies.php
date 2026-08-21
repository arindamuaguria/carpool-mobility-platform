<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\User;

use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\User\RecordsSessionAnomalies;
use Cmp\Application\User\SessionRefusalCause;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\UserReference;
use Psr\Log\LoggerInterface;

/**
 * `SEC-206` ‡'s session anomaly, written where `SEC-203` ‡ and `SEC-204` put it.
 *
 * A token whose owner the platform knows is **conduct** and goes to the
 * evidential log. A token that names nobody is the platform's own health and goes
 * to the operational log. {@see RecordsSessionAnomalies} carries the reasoning;
 * this is the writing.
 *
 * ## The six fields, for the evidential half
 *
 * `SEC-207` ‡ and `BE-107` ‡ fix them, and each has an occupant here:
 *
 * - **actor** — whose session it was, opaque by `DB-024` ‡.
 * - **action** — `session.anomaly`. What occurred is the presentation of a token
 *   that would not serve.
 * - **subject** — the same reference. The session itself cannot be the subject:
 *   naming it would mean naming its token hash, and `SEC-208` ‡ forbids that.
 * - **outcome** — `Refused`.
 * - **reason** — the **internal** cause. `SEC-048` ‡ keeps the three
 *   indistinguishable to a caller; an operator reading this needs the difference,
 *   which is what {@see SessionRefusalCause} is for.
 * - **time** — from the {@see Clock}.
 *
 * ## Written after the refusal is decided, never before
 *
 * `BE-202` and the pattern `EvidentialAuthorisationRefusals` set: the record
 * exists because something happened, so it is written on the refusal path and
 * nowhere else. A record written speculatively would be a record of a decision
 * nobody took.
 *
 * ## Why this does not open a transaction
 *
 * `BE-106` ‡ binds an evidential record to the transaction carrying its effect,
 * and a refused session **has no effect** — nothing changed, so there is nothing
 * to commit with. That is the same position `EstablishSession::refuse()` takes.
 */
final class RecordedSessionAnomalies implements RecordsSessionAnomalies
{
    public const ACTION = 'session.anomaly';

    public function __construct(
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function record(SessionRefusalCause $cause, ?UserReference $user): void
    {
        if (! $user instanceof UserReference) {
            // SEC-204: nobody's conduct, so the platform's own health. No actor
            // exists and BE-107 ‡ refuses a record that cannot say what it is
            // about — inventing one would be inventing the very thing the record
            // is for.
            //
            // SEC-208 ‡ / SEC-210: the cause and nothing else. There is no token
            // here to leave out, because none was passed in.
            $this->logger->notice(self::ACTION, [
                'cause' => $cause->name,
                'detail' => $cause->describe(),
                // BE-202: says plainly what this line is not.
                'evidential' => false,
            ]);

            return;
        }

        // SEC-203 ‡: an actor's conduct, and DB-044 ‡'s detection — the row was
        // retained precisely so that reuse could be noticed, and this is the
        // noticing.
        $this->evidence->record(Evidence::of(
            ActorReference::fromString($user->toString()),
            self::ACTION,
            $user->toString(),
            EvidentialOutcome::Refused,
            $this->clock->now(),
            $cause->describe(),
        ));
    }
}
