<?php

declare(strict_types=1);

namespace Tests\Domain\Persistence;

use Cmp\Infrastructure\Persistence\Schema\DestructiveMigrationGuard;
use PHPUnit\Framework\Attributes\Test;
use Tests\Domain\DomainTestCase;

/**
 * CMP-IMP-046 — `DB-218` ‡ a destructive migration requires explicit recorded
 * approval.
 *
 * Level 2: the guard reads files and holds no database state.
 */
final class DestructiveMigrationGuardTest extends DomainTestCase
{
    private string $migrationDirectory;

    private string $registerPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrationDirectory = sys_get_temp_dir().'/cmp-migrations-'.bin2hex(random_bytes(6));
        $this->registerPath = $this->migrationDirectory.'/DESTRUCTIVE-APPROVALS.md';

        mkdir($this->migrationDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->migrationDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->migrationDirectory);

        parent::tearDown();
    }

    #[Test]
    public function an_additive_migration_needs_no_approval(): void
    {
        $this->writeMigration('2026_08_19_000000_create_op_rides_table.php', <<<'PHP'
            <?php
            return new class {
                public function up(): void {
                    Schema::create('op_rides', function ($table) {
                        $table->id();
                        $table->string('external_id');
                    });
                }
            };
            PHP);

        self::assertSame([], $this->guard()->unapprovedDestructiveMigrations());
    }

    #[Test]
    public function a_migration_that_drops_a_column_without_approval_is_refused(): void
    {
        // Negative test for DB-218 ‡.
        $this->writeMigration('2026_08_19_000001_remove_a_column.php', <<<'PHP'
            <?php
            return new class {
                public function up(): void {
                    Schema::table('op_rides', function ($table) {
                        $table->dropColumn('external_id');
                    });
                }
            };
            PHP);

        $violations = $this->guard()->unapprovedDestructiveMigrations();

        self::assertCount(1, $violations);
        self::assertSame('DB-218', $violations[0]->statement());
        self::assertStringContainsString('dropColumn', $violations[0]->detail());
    }

    #[Test]
    public function a_migration_that_drops_a_constraint_without_approval_is_refused(): void
    {
        // DB-204 ‡ is stronger still: a constraint is never dropped to make a
        // migration succeed, and such a migration is rejected rather than
        // approved. The guard surfaces it for that decision.
        $this->writeMigration('2026_08_19_000002_relax_a_constraint.php', <<<'PHP'
            <?php
            return new class {
                public function up(): void {
                    Schema::table('op_ride_seat_allocations', function ($table) {
                        $table->dropUnique('op_ride_seat_allocations_ride_id_unique');
                    });
                }
            };
            PHP);

        self::assertCount(1, $this->guard()->unapprovedDestructiveMigrations());
    }

    #[Test]
    public function a_destructive_migration_named_in_the_register_passes(): void
    {
        $this->writeMigration('2026_08_19_000003_remove_a_column.php', <<<'PHP'
            <?php
            return new class {
                public function up(): void {
                    Schema::table('op_rides', function ($table) {
                        $table->dropColumn('external_id');
                    });
                }
            };
            PHP);

        file_put_contents($this->registerPath, <<<'MD'
            | Migration | What is dropped | Approved by | Date |
            |---|---|---|---|
            | `2026_08_19_000003_remove_a_column.php` | op_rides.external_id | Project Owner | 2026-08-19 |
            MD);

        self::assertSame([], $this->guard()->unapprovedDestructiveMigrations());
    }

    #[Test]
    public function a_word_that_merely_resembles_a_drop_does_not_fire(): void
    {
        // TC-041: a rule narrow enough to produce no false positive in correct
        // code. `dropped_at` is a column name, not an operation.
        $this->writeMigration('2026_08_19_000004_add_a_column.php', <<<'PHP'
            <?php
            // This migration adds a column and drops nothing.
            return new class {
                public function up(): void {
                    Schema::table('op_rides', function ($table) {
                        $table->timestamp('dropped_at')->nullable();
                    });
                }
            };
            PHP);

        self::assertSame([], $this->guard()->unapprovedDestructiveMigrations());
    }

    #[Test]
    public function the_shipped_register_records_no_approval(): void
    {
        // The platform has never dropped anything, and nothing in it may record
        // an approval on its own behalf.
        $guard = new DestructiveMigrationGuard(
            dirname(__DIR__, 3).'/database/migrations',
            dirname(__DIR__, 3).'/database/migrations/DESTRUCTIVE-APPROVALS.md',
        );

        self::assertSame([], $guard->approvedMigrations());
        self::assertSame([], $guard->unapprovedDestructiveMigrations());
    }

    private function guard(): DestructiveMigrationGuard
    {
        return new DestructiveMigrationGuard($this->migrationDirectory, $this->registerPath);
    }

    private function writeMigration(string $name, string $contents): void
    {
        file_put_contents($this->migrationDirectory.'/'.$name, $contents);
    }
}
