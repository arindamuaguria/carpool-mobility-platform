<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Evidence;

use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Domain\Shared\Time\Instant;
use InvalidArgumentException;

/**
 * One thing that happened, as the evidential log will record it.
 *
 * `BE-107` ‡ / `DB-109` ‡: **actor, action, subject, time, outcome and reason.**
 * `FRD-FR-245` ‡ says the same in plainer words — what occurred, when, its
 * subject, and the party responsible — and `FRD-FR-246` ‡ adds that where an
 * operator acted, the operator is named.
 *
 * **There is deliberately no free-form context field.** `BE-201` ‡ forbids a
 * payment credential, a precise location or a contact detail reaching a log, and
 * a general-purpose bag is where all three arrive. `SADR-10` and `DB-037` make
 * the payment case absolute: no payment instrument surface anywhere, in any form.
 * Six fields are what the chain specifies; if an operator needs a seventh, that
 * is a change to CMP-DOC-09 §9 and not a field added here.
 *
 * `reason` is nullable, and deliberately: `DB-109` ‡ requires `NOT NULL` on
 * actor, action, subject, outcome and time, and **not** on reason. A successful
 * operation has no reason to give. `BE-114` ‡ requires one where the operation
 * was refused.
 */
final class Evidence
{
    private function __construct(
        private readonly ActorReference $actor,
        private readonly string $action,
        private readonly string $subject,
        private readonly EvidentialOutcome $outcome,
        private readonly ?string $reason,
        private readonly Instant $occurredAt,
    ) {}

    /**
     * @param  string  $action  what occurred (`FRD-FR-245` ‡)
     * @param  string  $subject  what it was done to — an **external** identifier,
     *                           because `DB-024` ‡ keeps the internal key out of
     *                           anything a caller can read
     */
    public static function of(
        ActorReference $actor,
        string $action,
        string $subject,
        EvidentialOutcome $outcome,
        Instant $occurredAt,
        ?string $reason = null,
    ): self {
        foreach (['action' => $action, 'subject' => $subject] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf(
                    'BE-107 ‡: an evidential record captures its %s. A record that cannot say what it is '
                    .'about is not evidence of anything.',
                    $field,
                ));
            }
        }

        // BE-114 ‡: a refused operation is evidenced with its refusal reason.
        if ($outcome === EvidentialOutcome::Refused && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException(
                'BE-114 ‡: a refused operation is evidenced with its refusal reason.'
            );
        }

        return new self($actor, $action, $subject, $outcome, $reason, $occurredAt);
    }

    public function actor(): ActorReference
    {
        return $this->actor;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function outcome(): EvidentialOutcome
    {
        return $this->outcome;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function occurredAt(): Instant
    {
        return $this->occurredAt;
    }
}
