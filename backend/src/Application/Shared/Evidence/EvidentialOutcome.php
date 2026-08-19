<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Evidence;

/**
 * What happened, as an evidential record states it (`BE-107` ‡, `DB-109` ‡).
 *
 * Three outcomes, because an evidential record answers one question: did the
 * thing happen, was it declined, or did the platform fail. `BE-114` ‡ requires a
 * refused operation to be evidenced **with its refusal reason**, which is why
 * `Refused` is not folded into `Failed` — a refusal is a decision the platform
 * made and a failure is not.
 *
 * `DB-039` puts an enumerated column whose value set is a **business decision**
 * into policy configuration rather than a database `ENUM`. This set is not a
 * business decision — it describes what the platform observed about its own
 * operation — so it is code, and the column that stores it is a plain string
 * rather than a database `ENUM`. If it turns out to be a business decision, it
 * moves to the policy store.
 */
enum EvidentialOutcome: string
{
    /** The operation took effect. */
    case Succeeded = 'succeeded';

    /** A rule declined it (`BE-114` ‡ — the reason is recorded with it). */
    case Refused = 'refused';

    /** The platform failed. `BE-186` ‡ keeps this distinct from a refusal. */
    case Failed = 'failed';
}
