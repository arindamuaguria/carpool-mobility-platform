<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\IntegrityConstraints;

/**
 * `CMP-IMP-050` — the integrity constraint register, checked as a register.
 *
 * Level 1. Everything here is decidable by reading the register and the file
 * system, so `TC-033` puts it at the earliest level that can settle it. Whether
 * the deployed schema actually holds what the register claims is level 3's, in
 * `IntegrityConstraintsHoldTest`.
 *
 * `DB-207` ‡ is the obligation: **each of the twenty-one constraints shall have a
 * test that attempts to violate it and requires the database to refuse.** Eleven
 * do. The other ten cannot, because the tables they protect do not exist — and
 * the point of this test is that the ten say so, individually, rather than the
 * register quietly having eleven rows.
 */
final class IntegrityConstraintRegisterTest extends TestCase
{
    /**
     * What an entry that is not enforced-and-tested has to say for itself: a
     * missing table, an open decision, a gap, a feature, or an aggregate that
     * `BE-017` fixes but nobody has built.
     *
     * Deliberately not a prose match. A reviewer writes the note in whatever
     * words fit the constraint; what the build requires is that a blocker is
     * named at all.
     */
    private const NAMES_A_BLOCKER = '/(not exist|none exists|no \w+ table|is open|GAP-\d|FEAT-\d|BAD-DEC-\d|BE-017|undecided|excluded)/i';

    public function test_the_register_holds_all_twenty_one_constraints(): void
    {
        // DB-212: reviewable as a single list. Twenty-one is the number
        // CMP-DOC-11 §15 states, and a register that had grown or shrunk without
        // a documentation change would be the register disagreeing with its own
        // source.
        self::assertSame(range(1, 21), array_keys(IntegrityConstraints::all()));
    }

    public function test_every_constraint_names_what_it_protects(): void
    {
        foreach (IntegrityConstraints::all() as $number => $entry) {
            self::assertNotSame('', trim($entry['constraint']), 'Constraint '.$number.' must say what it constrains.');
            self::assertMatchesRegularExpression(
                '/[A-Z]+(-[A-Z]+)*-[0-9]{3}/',
                $entry['protects'],
                'Constraint '.$number.' must cite the statement it protects.',
            );
            self::assertNotSame('', trim($entry['note']), 'Constraint '.$number.' must carry its position.');
        }
    }

    public function test_every_enforced_constraint_names_a_violation_test_that_exists(): void
    {
        // DB-207 ‡. A named test that no longer exists would be the register
        // claiming a verification nobody runs — the same rot an allow-list
        // develops when it outlives its entries.
        foreach (IntegrityConstraints::all() as $number => $entry) {
            if ($entry['status'] !== IntegrityConstraints::ENFORCED || $entry['violationTest'] === null) {
                continue;
            }

            self::assertFileExists(
                self::basePath($entry['violationTest']),
                'Constraint '.$number.' names a violation test that does not exist.',
            );
        }
    }

    public function test_an_enforced_constraint_without_a_violation_test_says_why(): void
    {
        // DB-207 ‡ admits no exception, so an entry that is enforced and untested
        // has to account for itself in the register rather than in a reviewer's
        // memory. Constraint 9 is the one: the grant is issued, and attempting the
        // forbidden operation needs a led_ table that does not exist.
        $unattested = [];

        foreach (IntegrityConstraints::all() as $number => $entry) {
            if ($entry['status'] !== IntegrityConstraints::ENFORCED || $entry['violationTest'] !== null) {
                continue;
            }

            $unattested[] = $number;

            self::assertMatchesRegularExpression(
                self::NAMES_A_BLOCKER,
                $entry['note'],
                'Constraint '.$number.' is enforced and untested, and must say what prevents the test.',
            );
        }

        self::assertSame([9], $unattested, 'A second untested-but-enforced constraint has appeared; DB-207 ‡ wants a reason for each.');
    }

    public function test_every_absent_constraint_names_what_it_waits_for(): void
    {
        // The register ships mostly unenforced, which is the honest state of a
        // schema whose aggregates are unbuilt. "Absent" with no reason would be
        // indistinguishable from forgotten.
        foreach (IntegrityConstraints::all() as $number => $entry) {
            if ($entry['status'] !== IntegrityConstraints::ABSENT) {
                continue;
            }

            self::assertNull($entry['object'], 'Constraint '.$number.' is absent, so it can name no schema object.');
            self::assertNull($entry['violationTest'], 'Constraint '.$number.' is absent, so nothing can be violating it.');
            self::assertMatchesRegularExpression(
                self::NAMES_A_BLOCKER,
                $entry['note'],
                'Constraint '.$number.' must name what its absence waits on.',
            );
        }
    }

    public function test_the_ratings_constraint_is_withheld_and_not_merely_absent(): void
    {
        // CLAUDE.md §5 and CMP-DOC-04 §9.2: ratings carries zero functional
        // requirements, and CC-025 records that CMP-DOC-11 §6.7 specifies a table
        // for it in error. ADM-187/ADM-191 forbid a withheld item even disabled or
        // flagged, so this entry must never quietly become "absent, awaiting the
        // aggregate" — which is what it would look like if nobody had written this
        // down.
        $constraint = IntegrityConstraints::all()[18];

        self::assertSame(IntegrityConstraints::WITHHELD, $constraint['status']);
        self::assertStringContainsString('MUST NOT BE BUILT', $constraint['note']);
        self::assertStringContainsString('CC-025', $constraint['note']);

        // And it is the only one. A second withheld entry would mean a withheld
        // area had acquired a constraint, which is a decision nobody has taken.
        $withheld = array_keys(array_filter(
            IntegrityConstraints::all(),
            static fn (array $entry): bool => $entry['status'] === IntegrityConstraints::WITHHELD,
        ));

        self::assertSame([18], $withheld);
    }

    public function test_a_constraint_cannot_be_dropped_to_make_a_migration_succeed(): void
    {
        // DB-204 ‡. The guard already refuses an unapproved dropForeign,
        // dropIndex, dropUnique or dropPrimary; this asserts the four are in its
        // list, because DB-204 ‡ is the reason they are there and a list is easy
        // to shorten by accident.
        $guard = file_get_contents(self::basePath('src/Infrastructure/Persistence/Schema/DestructiveMigrationGuard.php'));
        self::assertIsString($guard);

        foreach (['dropForeign', 'dropIndex', 'dropUnique', 'dropPrimary', 'dropColumn'] as $operation) {
            self::assertStringContainsString(
                "'".$operation."'",
                $guard,
                'DB-204 ‡: dropping a constraint is destructive and needs recorded approval.',
            );
        }
    }

    public function test_the_enforced_count_is_stated_rather_than_counted_by_a_reader(): void
    {
        // DB-208 requires a constraint added later to reach this register and its
        // test set in the same change. This is what makes that visible: the number
        // moves, and the change that moves it is the change that added the
        // constraint.
        $byStatus = array_count_values(array_column(IntegrityConstraints::all(), 'status'));

        self::assertSame(11, $byStatus[IntegrityConstraints::ENFORCED] ?? 0);
        self::assertSame(9, $byStatus[IntegrityConstraints::ABSENT] ?? 0);
        self::assertSame(1, $byStatus[IntegrityConstraints::WITHHELD] ?? 0);
    }

    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
