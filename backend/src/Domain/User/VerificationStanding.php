<?php

declare(strict_types=1);

namespace Cmp\Domain\User;

/**
 * Whether control of the account's phone number has been demonstrated.
 *
 * `BAD-RULE-006`, decided by the Project Owner on 2026-08-20 and **Absolute**:
 * the vocabulary is `UNVERIFIED` and `VERIFIED`, and an account is `VERIFIED`
 * once control of its registered number has been demonstrated.
 *
 * `BAD-RULE-006` is also explicit that verification status *"is determined and
 * held by the backend; it is never asserted by a client"* — `DB-041` ‡ gives it
 * no inbound write path, `API-125` ‡ makes it readable and never writable, and
 * `API-037` ‡ keeps it out of every request schema — a request naming it is
 * already refused in whole, and recorded as an integrity event, by the schema
 * mechanism `CMP-IMP-468` built. Which class does that is the Application
 * layer's, and `BE-002` keeps this one from naming it, in a docblock as much as
 * in an import.
 *
 * ## Two values, and what they are not
 *
 * This is **phone-number standing** and nothing wider. `BAD-RULE-008` still asks
 * what verification levels exist **beyond** it, what evidence each requires and
 * what each permits — identity documents, vehicle verification, driver
 * eligibility — and that remains `[TBD – BAD-DEC-005]` with `FRD-GAP-002` open.
 * A third case must not be added here to represent one of them; a level is a
 * different concept from a standing, and `BAD-DEC-005` decides whether it is even
 * modelled this way.
 */
enum VerificationStanding: string
{
    /**
     * `FRD-FR-006`: an account is created in this state, and it *"does not permit
     * participation"*.
     */
    case Unverified = 'UNVERIFIED';

    /**
     * `FRD-FR-008`: reached only after control of the phone number has been
     * demonstrated.
     */
    case Verified = 'VERIFIED';

    /**
     * `FRD-FR-006` / `FRD-FR-008`: participation requires a demonstrated number.
     *
     * What "participation" covers — publishing, requesting, booking, travelling —
     * is `BAD-RULE-004`'s, and is not restated here.
     */
    public function permitsParticipation(): bool
    {
        return $this === self::Verified;
    }
}
