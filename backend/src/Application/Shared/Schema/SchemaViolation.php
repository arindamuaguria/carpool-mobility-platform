<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Schema;

/**
 * One breach of a schema convention, named with the statement it breaches so the
 * message states the rule (`DB-019` in spirit — a violation names its rule).
 */
final class SchemaViolation
{
    public function __construct(
        private readonly string $statement,
        private readonly string $subject,
        private readonly string $detail,
    ) {}

    /** The CMP-DOC-11 statement breached, e.g. `DB-009`. */
    public function statement(): string
    {
        return $this->statement;
    }

    /** The table, column, index or constraint that breaches it. */
    public function subject(): string
    {
        return $this->subject;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    public function describe(): string
    {
        return sprintf('%s — %s: %s', $this->statement, $this->subject, $this->detail);
    }
}
