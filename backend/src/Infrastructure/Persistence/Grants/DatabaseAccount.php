<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Grants;

/**
 * The three database accounts of `DADR-09`.
 *
 * > *"The **application** account holds `SELECT`, `INSERT`, `UPDATE`, `DELETE` on
 * > operational, projection and machinery domains, and `SELECT`, `INSERT` only on
 * > the evidential and ledger domains — no `UPDATE`, no `DELETE`, no `DDL`. The
 * > **migration** account holds `DDL` and is used only by migrations. The
 * > **read** account holds `SELECT` and is used for reporting. No account is
 * > issued to any component other than the platform."*
 *
 * Constraints 8, 9 and 10 of the CMP-DOC-11 §15 register are these grants.
 * `DB-205` ‡ notes they are grants, not schema objects, and are asserted by a
 * check that attempts the forbidden operation.
 *
 * The tracker's wording for `CMP-IMP-047` names an "evidential-write account".
 * `DADR-09` and `DB-215` name a **migration** account; DOC-11 governs.
 */
enum DatabaseAccount: string
{
    case Application = 'application';
    case Migration = 'migration';
    case Read = 'read';
}
