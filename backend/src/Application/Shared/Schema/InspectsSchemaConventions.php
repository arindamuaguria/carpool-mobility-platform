<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Schema;

/**
 * Reports breaches of the schema conventions of CMP-DOC-11 §4 and §5.
 *
 * Declared here rather than in Infrastructure so that a caller — the console
 * surface, the migration path — depends on the obligation and not on the MySQL
 * implementation of it (`BE-003`, `BE-005`).
 */
interface InspectsSchemaConventions
{
    /**
     * @return list<SchemaViolation>
     */
    public function inspect(): array;
}
