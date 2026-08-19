<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Persistence\Schema;

use Cmp\Application\Shared\Schema\ApprovesDestructiveMigrations;
use Cmp\Application\Shared\Schema\SchemaViolation;

/**
 * `DB-218` ‡ — a destructive migration requires explicit recorded approval.
 *
 * Dropping a column, a table or a constraint destroys evidence somebody may be
 * entitled to. `DB-204` ‡ goes further: a constraint is never dropped to make a
 * migration succeed, and a migration that requires it dropped is rejected.
 *
 * The mechanism: a migration containing a destructive operation must be listed
 * in the approvals register, naming what is dropped and who approved it. An
 * unlisted destructive migration fails the check — it is not warned about, and
 * it cannot be suppressed.
 *
 * **Approval is the Project Owner's.** Nothing in the platform may add an entry
 * to the register on its own behalf.
 *
 * Pure: reads files, touches no database.
 */
final class DestructiveMigrationGuard implements ApprovesDestructiveMigrations
{
    /**
     * Schema builder calls that drop something.
     *
     * `dropIfExists` is included: "if it exists" changes nothing about what is
     * lost when it does.
     */
    public const DESTRUCTIVE_OPERATIONS = [
        'drop',
        'dropIfExists',
        'dropColumn',
        'dropColumns',
        'dropConstrainedForeignId',
        'dropForeign',
        'dropIndex',
        'dropUnique',
        'dropPrimary',
        'dropFullText',
        'dropSpatialIndex',
        'dropRememberToken',
        'dropSoftDeletes',
        'dropTimestamps',
        'dropMorphs',
        'renameColumn',
        'rename',
        'truncate',
    ];

    public function __construct(
        private readonly string $migrationDirectory,
        private readonly string $approvalsRegisterPath,
    ) {}

    /**
     * Migrations that drop something without a recorded approval.
     *
     * @return list<SchemaViolation>
     */
    public function unapprovedDestructiveMigrations(): array
    {
        $approved = $this->approvedMigrations();
        $violations = [];

        foreach ($this->migrationFiles() as $path) {
            $file = basename($path);
            $contents = (string) file_get_contents($path);
            $operations = $this->destructiveOperationsIn($contents);

            if ($operations === [] || in_array($file, $approved, true)) {
                continue;
            }

            $violations[] = new SchemaViolation(
                'DB-218',
                $file,
                sprintf(
                    'the migration performs %s but carries no recorded approval. A destructive migration '
                    .'requires the Project Owner to record approval in %s; it cannot be self-approved.',
                    implode(', ', $operations),
                    basename($this->approvalsRegisterPath),
                ),
            );
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public function destructiveOperationsIn(string $contents): array
    {
        $found = [];

        foreach (self::DESTRUCTIVE_OPERATIONS as $operation) {
            // Matched as a method call so that a column named `dropped_at` or a
            // comment mentioning the word does not fire (`TC-041`).
            if (preg_match('/->\s*'.preg_quote($operation, '/').'\s*\(/', $contents) === 1) {
                $found[] = $operation;
            }
        }

        return $found;
    }

    /**
     * Migration file names the register records as approved.
     *
     * @return list<string>
     */
    public function approvedMigrations(): array
    {
        if (! is_file($this->approvalsRegisterPath)) {
            return [];
        }

        $contents = (string) file_get_contents($this->approvalsRegisterPath);
        preg_match_all('/`([0-9_]+_[a-z0-9_]+\.php)`/', $contents, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob($this->migrationDirectory.'/*.php');

        return $files === false ? [] : $files;
    }
}
