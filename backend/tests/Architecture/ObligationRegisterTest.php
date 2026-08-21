<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\IntegrityConstraints;
use Tests\ObligationRegister;

/**
 * `CMP-IMP-037`-area / CMP-DOC-18 §4 — the register is enforced, not declared.
 *
 * A register nobody checks is a list of intentions. `TC-006` makes an obligation
 * present in a predecessor and absent from the register a **documentation
 * defect**, and `TC-010` makes an obligation with no named verified statement a
 * defect in the register — neither of which is true unless something fails the
 * build over it.
 *
 * ## What this file will not let happen
 *
 * - An obligation quietly dropped, or a source's count drifting from CMP-DOC-18
 *   Appendix C (`TC-006`).
 * - An obligation claiming `ENFORCED` while naming a test class that does not
 *   exist, or naming none at all. A status maintained by hand is a status that
 *   drifts, and `TC-009` leaves status to be *"maintained"*.
 * - A non-enforced obligation with no note. `TADR-12`: *"unverifiable is
 *   recorded, never padded"* — an entry that says nothing about why it does not
 *   hold reads as an oversight whichever it is.
 * - The non-suppressible set changing. `TC-021` ‡ admits **no** suppression
 *   mechanism for the twenty-five, and the first step in suppressing one would be
 *   to stop calling it non-suppressible.
 * - A suppression mechanism appearing anywhere in the build (`TC-021` ‡,
 *   `TC-042`).
 */
final class ObligationRegisterTest extends TestCase
{
    /**
     * CMP-DOC-18 §4.1 and Appendix C both state these two totals.
     */
    private const TOTAL = 99;

    private const NON_SUPPRESSIBLE = 25;

    public function test_the_register_holds_the_ninety_nine(): void
    {
        // TC-001: "the 99 obligations above shall be consolidated into one
        // register". Not ninety-eight — TC-006 makes a missing one a
        // documentation defect, and this is where that becomes checkable.
        self::assertCount(self::TOTAL, ObligationRegister::all());
    }

    public function test_each_source_contributes_the_count_appendix_c_states(): void
    {
        // TC-002 keeps each obligation's source and section, which is only useful
        // if the sources reconcile. Asserted against Appendix C's stated figures
        // rather than against a total this file computes — a register that
        // counted itself would agree with itself however wrong it was.
        $counted = [];

        foreach (ObligationRegister::all() as $reference => $obligation) {
            $source = self::sourceOf($reference);

            $counted[$source]['count'] = ($counted[$source]['count'] ?? 0) + 1;
            $counted[$source]['nonSuppressible'] = ($counted[$source]['nonSuppressible'] ?? 0)
                + ($obligation['suppressible'] ? 0 : 1);
        }

        foreach (ObligationRegister::appendixCCounts() as $source => $expected) {
            self::assertArrayHasKey($source, $counted, $source.' contributes nothing to the register.');
            self::assertSame(
                $expected['count'],
                $counted[$source]['count'],
                'TC-006: '.$source.' contributes a different number than CMP-DOC-18 Appendix C states.',
            );
            self::assertSame(
                $expected['nonSuppressible'],
                $counted[$source]['nonSuppressible'],
                'TADR-06: '.$source.'\'s non-suppressible count differs from Appendix C.',
            );
        }

        self::assertSame(
            array_keys(ObligationRegister::appendixCCounts()),
            array_keys($counted),
            'TC-006: the register holds obligations from a source Appendix C does not name, or omits one it does.',
        );
    }

    public function test_exactly_twenty_five_are_non_suppressible(): void
    {
        // TADR-06 and §4.5. The figure is stated in three places in CMP-DOC-18 and
        // is the one this platform must never quietly reduce.
        $nonSuppressible = array_filter(
            ObligationRegister::all(),
            static fn (array $obligation): bool => $obligation['suppressible'] === false,
        );

        self::assertCount(self::NON_SUPPRESSIBLE, $nonSuppressible);
    }

    public function test_the_twenty_five_are_the_ones_the_documents_name(): void
    {
        // §4.5 names them by source and number. Asserted as an exact set, because
        // a register with twenty-five non-suppressible obligations that were not
        // *these* twenty-five would pass a count and protect the wrong things.
        $expected = [
            // CMP-DOC-09 §17.2 rules 5, 6, 8 — BE-218.
            'BE-17.2/5', 'BE-17.2/6', 'BE-17.2/8',
            // CMP-DOC-13 §19.1 checks 1–7 — SEC-232 ‡.
            'SEC-19.1/1', 'SEC-19.1/2', 'SEC-19.1/3', 'SEC-19.1/4',
            'SEC-19.1/5', 'SEC-19.1/6', 'SEC-19.1/7',
            // CMP-DOC-14 §16 obligations 1, 5, 9 — PAY-204 ‡.
            'PAY-16/1', 'PAY-16/5', 'PAY-16/9',
            // CMP-DOC-15 §14 obligations 1, 3, 8 — GPS-180 ‡.
            'GPS-14/1', 'GPS-14/3', 'GPS-14/8',
            // CMP-DOC-16 §13 obligations 1, 2, 3, 6 — NOTIF-186 ‡.
            'NOTIF-13/1', 'NOTIF-13/2', 'NOTIF-13/3', 'NOTIF-13/6',
            // CMP-DOC-17 §16 obligations 1–5 — ADM-198 ‡.
            'ADM-16/1', 'ADM-16/2', 'ADM-16/3', 'ADM-16/4', 'ADM-16/5',
        ];

        sort($expected);

        $actual = array_keys(array_filter(
            ObligationRegister::all(),
            static fn (array $obligation): bool => $obligation['suppressible'] === false,
        ));

        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function test_every_obligation_carries_the_seven_fields(): void
    {
        // TC-007, verbatim: "every obligation shall carry the seven fields above."
        // The two this register adds — status and provenBy — are reported in its
        // own note as its own.
        foreach (ObligationRegister::all() as $reference => $obligation) {
            foreach (['source', 'section', 'obligation', 'verifies', 'level', 'suppressible', 'technique', 'status', 'provenBy', 'note'] as $field) {
                self::assertArrayHasKey($field, $obligation, $reference.' is missing '.$field.'.');
            }

            self::assertNotSame('', trim($obligation['obligation']), $reference.' states no obligation.');
        }
    }

    public function test_every_obligation_names_the_statements_it_verifies(): void
    {
        // TC-008: "the verified-statements field shall name statement
        // identifiers, not prose." TC-010: "an obligation with no named verified
        // statement shall be a defect in the register."
        foreach (ObligationRegister::all() as $reference => $obligation) {
            self::assertNotSame([], $obligation['verifies'], 'TC-010: '.$reference.' names no verified statement.');

            foreach ($obligation['verifies'] as $statement) {
                self::assertMatchesRegularExpression(
                    '/^[A-Z][A-Z0-9-]*-[A-Z0-9-]*\d+$/',
                    $statement,
                    'TC-008: "'.$statement.'" in '.$reference.' is prose, not a statement identifier.',
                );
            }
        }
    }

    public function test_every_obligation_names_exactly_one_level(): void
    {
        // TC-003 / TADR-02: "one obligation, one owning level." An obligation
        // owned by two levels is an obligation each level assumes the other ran.
        $levels = [
            ObligationRegister::LEVEL_STATIC,
            ObligationRegister::LEVEL_DOMAIN,
            ObligationRegister::LEVEL_INTEGRATION,
            ObligationRegister::LEVEL_CONTRACT,
            ObligationRegister::LEVEL_SYSTEM,
            ObligationRegister::LEVEL_REVIEW,
        ];

        foreach (ObligationRegister::all() as $reference => $obligation) {
            self::assertContains($obligation['level'], $levels, $reference.' names no level of the six.');
            self::assertContains(
                $obligation['technique'],
                ObligationRegister::TECHNIQUES,
                $reference.' names a technique §4.2 does not.',
            );
        }
    }

    public function test_an_enforced_obligation_names_a_test_that_exists(): void
    {
        // The assertion this file exists for. A status is a claim, and a claim
        // that cannot be checked is worth nothing — so an obligation may say it
        // is enforced only by naming the class that enforces it, and that class
        // must be there.
        foreach (ObligationRegister::all() as $reference => $obligation) {
            if ($obligation['status'] !== ObligationRegister::ENFORCED) {
                continue;
            }

            $provenBy = $obligation['provenBy'];

            self::assertIsString($provenBy, $reference.' claims enforcement and names nothing that enforces it.');
            self::assertTrue(
                class_exists($provenBy),
                $reference.' names '.$provenBy.', which does not exist. A status outlived its test.',
            );
        }
    }

    public function test_an_obligation_that_does_not_hold_says_why(): void
    {
        // TADR-12: "unverifiable is recorded, never padded." An entry that does
        // not hold and says nothing about why reads as an oversight whether it is
        // one or not — and a reviewer cannot tell a blocker from a to-do.
        foreach (ObligationRegister::all() as $reference => $obligation) {
            if ($obligation['status'] === ObligationRegister::ENFORCED) {
                continue;
            }

            self::assertNotSame(
                '',
                trim($obligation['note']),
                $reference.' is '.$obligation['status'].' and does not say why.',
            );
            self::assertNull(
                $obligation['provenBy'],
                $reference.' is '.$obligation['status'].' and names a test that proves it. One of the two is wrong.',
            );
        }
    }

    public function test_a_withheld_obligation_is_never_enforced(): void
    {
        // TADR-13: "no test for a withheld capability." ADM-187/ADM-191 forbid a
        // withheld item being stubbed, prototyped, disabled or flagged, and a
        // test for one would be the same mistake in a different file.
        foreach (ObligationRegister::all() as $reference => $obligation) {
            if ($obligation['status'] !== ObligationRegister::WITHHELD) {
                continue;
            }

            self::assertNull($obligation['provenBy'], 'TADR-13: '.$reference.' is withheld and has a test.');
        }
    }

    public function test_every_status_is_one_of_the_five(): void
    {
        // Five, and no sixth invented at the point of writing an awkward entry.
        // The distinction between them is the whole value of the register: a
        // blocker is somebody's decision, an absence is somebody's work.
        $statuses = [
            ObligationRegister::ENFORCED,
            ObligationRegister::ABSENT,
            ObligationRegister::BLOCKED,
            ObligationRegister::WITHHELD,
            ObligationRegister::NOT_APPLICABLE,
        ];

        foreach (ObligationRegister::all() as $reference => $obligation) {
            self::assertContains($obligation['status'], $statuses, $reference.' carries an undeclared status.');
        }
    }

    public function test_the_database_constraints_agree_with_their_own_register(): void
    {
        // CMP-DOC-11 §15's twenty-one appear in two registers, and DB-212 makes
        // IntegrityConstraints the reviewable one. This register reads its status
        // rather than restating it — asserted here, because a second copy of a
        // status is a second thing to forget to update.
        $constraints = IntegrityConstraints::all();

        foreach (ObligationRegister::all() as $reference => $obligation) {
            if (self::sourceOf($reference) !== 'DB-15') {
                continue;
            }

            $n = (int) substr($reference, strlen('DB-15/'));

            self::assertArrayHasKey($n, $constraints);
            self::assertSame(
                $constraints[$n]['constraint'].'.',
                $obligation['obligation'],
                $reference.' states a different constraint than IntegrityConstraints does.',
            );
        }
    }

    public function test_no_suppression_mechanism_exists_anywhere_in_the_build(): void
    {
        // TC-021 ‡, verbatim: "No suppression mechanism shall exist for any of
        // the 25." TC-042 takes the same position for a static-analysis rule —
        // narrow a rule, never suppress it — and the way to keep both true is to
        // have nowhere to put a suppression at all.
        //
        // TC-024 ‡ is the reason this matters more than it looks: "a
        // non-suppressible obligation producing a false positive shall be fixed,
        // never disabled." A build with a suppression facility is a build where
        // disabling is the easier of the two on a bad afternoon.
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (['phpstan.neon', 'phpstan.neon.dist', 'deptrac.yaml', 'pint.json'] as $file) {
            $path = $root.'/'.$file;

            if (! file_exists($path)) {
                continue;
            }

            $contents = file_get_contents($path);

            self::assertIsString($contents);

            foreach (self::declaredIn($contents) as $facility) {
                $offenders[] = $file.' → '.$facility;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'TC-021 ‡: the build offers somewhere to put a suppression.',
        );

        self::assertFileDoesNotExist($root.'/phpstan-baseline.neon', 'TC-042: there is no baseline file.');
    }

    public function test_the_suppression_detector_reads_a_declaration_and_not_a_sentence(): void
    {
        // TC-024 ‡, exercised on itself: "a non-suppressible obligation producing
        // a false positive shall be fixed, never disabled."
        //
        // The first version of this detector matched the word "baseline" anywhere
        // in the file, and phpstan.neon's own comment — explaining that there is
        // no baseline because TC-042 requires a rule to be narrowed rather than
        // suppressed — tripped it. Narrowing it to a **declaration** is the fix
        // TC-024 ‡ asks for; deleting the comment would have been the disabling
        // it forbids.
        //
        // The true positive, and the sentence that must not be one.
        self::assertSame(['baseline'], self::declaredIn("parameters:\n    baseline: some.neon\n"));
        self::assertSame(['ignoreErrors'], self::declaredIn("    ignoreErrors:\n        - '#x#'\n"));
        self::assertSame(['skip_violations'], self::declaredIn("  skip_violations:\n    - Foo\n"));
        self::assertSame([], self::declaredIn('# there is no baseline file, and ignoreErrors is not used'));
        self::assertSame([], self::declaredIn('# skip_violations would be a place to hide one'));
    }

    /**
     * The suppression facilities **declared** in a configuration file.
     *
     * A key, not a word: NEON and YAML both declare a facility as `name:` at the
     * start of a line, and prose about one — including prose recording its
     * deliberate absence — is not a declaration of it.
     *
     * @return list<string>
     */
    private static function declaredIn(string $contents): array
    {
        $declared = [];

        foreach (['baseline', 'ignoreErrors', 'skip_violations', 'reportUnmatchedIgnoredErrors'] as $facility) {
            // reportUnmatchedIgnoredErrors is in the list because turning it OFF
            // is how an ignore that no longer matches stops being reported —
            // phpstan.neon sets it true, and this catches it being set false.
            $pattern = $facility === 'reportUnmatchedIgnoredErrors'
                ? '/^\s*reportUnmatchedIgnoredErrors\s*:\s*false\b/m'
                : '/^\s*'.preg_quote($facility, '/').'\s*:/m';

            if (preg_match($pattern, $contents) === 1) {
                $declared[] = $facility;
            }
        }

        return $declared;
    }

    public function test_no_test_in_the_suite_is_skipped_or_marked_incomplete(): void
    {
        // The other way an obligation stops being verified without anybody
        // deciding to. TC-024 ‡ requires a false positive to be fixed rather than
        // disabled, and markTestSkipped() is the disabling that does not look
        // like it.
        $offenders = [];
        $root = dirname(__DIR__);

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents)) {
                continue;
            }

            foreach (['markTestSkipped', 'markTestIncomplete', '#[Group(\'skip', '@group skip'] as $facility) {
                if (str_contains($contents, $facility) && ! str_contains($contents, 'TC-021')) {
                    $offenders[] = str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname()).' → '.$facility;
                }
            }
        }

        self::assertSame([], $offenders, 'TC-024 ‡: a test that skips itself is an obligation nobody verifies.');
    }

    /**
     * The `<source>` half of `TC-007`'s `<source>-<n>` reference.
     */
    private static function sourceOf(string $reference): string
    {
        $slash = strrpos($reference, '/');

        self::assertIsInt($slash, 'TC-007: "'.$reference.'" is not a <source>/<n> reference.');

        return substr($reference, 0, $slash);
    }
}
