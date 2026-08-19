<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Schema;

use RuntimeException;

/**
 * Raised when the deployed schema breaches a convention of CMP-DOC-11 §4 or §5.
 *
 * `DB-014` and `DB-216` require the check to happen **at migration time**, so
 * this is thrown from the migration path and fails the run rather than warning.
 */
final class SchemaConventionViolated extends RuntimeException
{
    /**
     * @param  list<SchemaViolation>  $violations
     */
    public function __construct(private readonly array $violations)
    {
        parent::__construct(
            "The schema breaches CMP-DOC-11 conventions:\n  - ".
            implode("\n  - ", array_map(
                static fn (SchemaViolation $v): string => $v->describe(),
                $violations,
            ))
        );
    }

    /**
     * @return list<SchemaViolation>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
