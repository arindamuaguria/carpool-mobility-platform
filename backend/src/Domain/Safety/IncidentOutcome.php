<?php

declare(strict_types=1);

namespace Cmp\Domain\Safety;

use InvalidArgumentException;

/**
 * What happened, recorded before an incident may close.
 *
 * `BE-029` ‡: *"`SafetyIncident` shall not close without a recorded outcome."*
 * `API-171` ‡ says the same from the interface side and adds that an incident is
 * not closable through the client interface at all. `UC-051`'s minimal guarantee
 * is the plain-language form: *"No safety signal is ever lost, and **none is
 * closed without a record**."*
 *
 * ## A free value, deliberately, and nothing closes yet
 *
 * There is no vocabulary here — no `Resolved`, no `NoActionRequired`, no
 * `Escalated`. Those words belong to the **response protocol**, and
 * `BAD-DEC-011` has not defined one: `BRD-REQ-113` is Blocked, `UC-052` is
 * Outlined, and inventing an outcome set here would be inventing the protocol in
 * the one place nobody would look for it — while `ADM-187`/`ADM-191` forbid
 * standing something up for withheld behaviour.
 *
 * So this type exists to make `BE-029` ‡ enforceable — a closure must carry
 * *something* recorded — and **nothing in the platform closes an incident**.
 * `SafetyIncidentSurfaceTest` asserts that no caller can.
 *
 * What is validated is only what is decidable: not blank, and within the column.
 */
final class IncidentOutcome
{
    /**
     * `op_safety_incidents.outcome` is `VARCHAR(255)`.
     */
    private const MAXIMUM_LENGTH = 255;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                'BE-029 ‡: an incident does not close without a recorded outcome, and a blank one is not a record. '
                .'UC-051: "none is closed without a record."'
            );
        }

        if (mb_strlen($trimmed) > self::MAXIMUM_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'An outcome is at most %d characters, which is what op_safety_incidents.outcome holds.',
                self::MAXIMUM_LENGTH,
            ));
        }

        return new self($trimmed);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
