<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Schema;

/**
 * The outcome of one schema check: what was asserted, and what breached it.
 */
final class SchemaVerification
{
    /**
     * @param  list<SchemaViolation>  $violations
     */
    public function __construct(
        private readonly string $assertion,
        private readonly array $violations,
    ) {}

    /** What the check asserts, stated with the requirement it realises. */
    public function assertion(): string
    {
        return $this->assertion;
    }

    public function satisfied(): bool
    {
        return $this->violations === [];
    }

    /**
     * @return list<SchemaViolation>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
