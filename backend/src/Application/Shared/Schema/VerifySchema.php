<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Schema;

/**
 * Verifies the schema against CMP-DOC-11, in the order the checks matter.
 *
 * Orchestration only, no rule of its own (`BE-006`). The rules belong to the
 * components it calls; this decides the sequence and collects the outcome, so
 * that the console surface, the migration path and a test all get the same
 * answer through the same path.
 *
 * The order is deliberate:
 *  1. `DB-218` ‡ destructive-migration approval — reads files, so it can refuse
 *     before anything touches the database.
 *  2. `OPS-024` ‡ / `DB-217` ‡ `CHECK` enforcement — if the server does not
 *     enforce, the conventions below are worth less than they appear.
 *  3. `DB-002` … `DB-020` ‡ the conventions, against the schema that exists.
 */
final class VerifySchema
{
    public function __construct(
        private readonly ApprovesDestructiveMigrations $approvals,
        private readonly VerifiesCheckConstraintEnforcement $checkConstraints,
        private readonly InspectsSchemaConventions $conventions,
    ) {}

    public function verifyMigrationApprovals(): SchemaVerification
    {
        return new SchemaVerification(
            'DB-218 ‡ — no destructive migration lacks recorded approval.',
            $this->approvals->unapprovedDestructiveMigrations(),
        );
    }

    public function verifyCheckEnforcement(): SchemaVerification
    {
        try {
            $this->checkConstraints->verify();
        } catch (CheckConstraintsNotEnforced $notEnforced) {
            return new SchemaVerification(
                'OPS-024 ‡ — the server enforces CHECK constraints.',
                [new SchemaViolation('OPS-024/DB-217', 'the deployed server', $notEnforced->getMessage())],
            );
        }

        return new SchemaVerification('OPS-024 ‡ — the server enforces CHECK constraints.', []);
    }

    public function verifyConventions(): SchemaVerification
    {
        return new SchemaVerification(
            'CMP-DOC-11 §4 and §5 — the schema satisfies every mechanical convention.',
            $this->conventions->inspect(),
        );
    }
}
