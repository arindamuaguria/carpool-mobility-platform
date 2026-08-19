<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Schema;

/**
 * Reports migrations that drop something without recorded approval.
 *
 * `DB-218` ‡: a destructive migration — dropping a column, a table or a
 * constraint — requires explicit recorded approval. `DB-204` ‡: a constraint is
 * never dropped to make a migration succeed, and a migration that requires it
 * dropped is rejected rather than approved.
 */
interface ApprovesDestructiveMigrations
{
    /**
     * @return list<SchemaViolation>
     */
    public function unapprovedDestructiveMigrations(): array;
}
