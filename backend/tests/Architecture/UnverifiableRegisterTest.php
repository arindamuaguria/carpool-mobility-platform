<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\ObligationRegister;
use Tests\UnverifiableRegister;

/**
 * CMP-DOC-18 §14 and §15.3 — the categories a passing suite does not cover.
 *
 * `TC-181` ‡ is the statement this file serves: *"a fully passing suite leaves
 * the largest known risks in this product untouched, because they are undecided
 * rather than defective."* `TC-186` ‡ adds that presenting one without §14.3
 * alongside it would **materially misrepresent** the product's readiness.
 *
 * ## What this file will not let happen
 *
 * - A token test appearing for an unverifiable property, to make a report look
 *   complete (`TC-174` ‡).
 * - A provisional threshold being invented so an unmeasurable category can pass
 *   or fail (`TC-199`).
 * - A test being written **for** a withheld capability rather than for the
 *   platform's refusal of one (`TC-178` ‡, `TC-179` ‡).
 * - The six report categories collapsing into pass and fail (`TC-193` ‡).
 * - The undecided register being quietly emptied, which would make a green build
 *   look like it covered everything.
 */
final class UnverifiableRegisterTest extends TestCase
{
    public function test_the_three_properties_needing_judgement_are_cmp_doc_13s_three(): void
    {
        // §14.2, verbatim: "inherited unchanged from CMP-DOC-13 §19.4. This
        // document adds none." Asserted as an exact set, because TC-176 makes the
        // only route in a ‡ statement that TC-017's mapping finds genuinely
        // unverifiable — and TC-017 has not been done, so nothing has come through
        // it yet.
        self::assertSame(
            ['SEC-089', 'SEC-142', 'SEC-070'],
            array_keys(UnverifiableRegister::needingJudgement()),
        );
    }

    public function test_every_unverifiable_property_records_its_compensating_control(): void
    {
        // TC-175: "the three above shall be recorded as unverified with their
        // compensating controls." A property recorded as unverifiable with no
        // compensating control is a risk nobody is carrying.
        foreach (UnverifiableRegister::needingJudgement() as $statement => $entry) {
            self::assertNotSame('', trim($entry['whyUnverifiable']), $statement.' does not say why.');
            self::assertNotSame(
                '',
                trim($entry['compensatingControl']),
                $statement.': TC-175 requires the compensating control to be recorded.',
            );
        }
    }

    public function test_only_sec_070_can_ever_become_verifiable(): void
    {
        // TC-177, verbatim: "SEC-070 becomes verifiable when BAD-DEC-022 is
        // decided; the other two do not." The distinction matters for planning —
        // one of these three is waiting on somebody, and two never will be.
        $entries = UnverifiableRegister::needingJudgement();

        self::assertSame('BAD-DEC-022', $entries['SEC-070']['becomesVerifiableWhen']);
        self::assertNull($entries['SEC-089']['becomesVerifiableWhen']);
        self::assertNull($entries['SEC-142']['becomesVerifiableWhen']);
    }

    public function test_no_unverifiable_property_has_a_token_test(): void
    {
        // TC-174 ‡: "none shall be given a token test to make a report look
        // complete." The check is that no obligation in the other register claims
        // to prove one of these — because the moment something genuinely does,
        // the property stops being unverifiable and belongs there rather than
        // here.
        $claimed = [];

        foreach (ObligationRegister::all() as $reference => $obligation) {
            if ($obligation['status'] !== ObligationRegister::ENFORCED) {
                continue;
            }

            foreach ($obligation['verifies'] as $statement) {
                if (array_key_exists($statement, UnverifiableRegister::needingJudgement())) {
                    $claimed[] = $reference.' claims to verify '.$statement;
                }
            }
        }

        self::assertSame(
            [],
            $claimed,
            'TC-174 ‡: an obligation claims to verify a property CMP-DOC-18 §14.2 records as unverifiable. '
            .'One of the two registers is wrong.',
        );
    }

    public function test_no_provisional_threshold_is_invented_for_an_unmeasurable_category(): void
    {
        // TC-199, verbatim: "no provisional threshold shall be invented to make
        // these categories pass or fail." TC-196 ‡: each is measured and reported
        // as measurement, never as a passing test.
        //
        // The target is null for all five and this fails if one ever holds a
        // figure — which is what inventing a threshold looks like from here.
        self::assertCount(5, UnverifiableRegister::unmeasurable());

        foreach (UnverifiableRegister::unmeasurable() as $key => $entry) {
            self::assertNull($entry['target'], 'TC-199: '.$key.' has acquired a threshold.');
            self::assertNotSame('', trim($entry['unsetTarget']), $key.' does not name the unset target.');
            self::assertNotSame('', trim($entry['measuredInstead']), $key.' does not say what is measured.');
        }
    }

    public function test_the_six_report_categories_are_declared_and_distinct(): void
    {
        // TC-193 ‡: "the six categories above shall never be collapsed into pass
        // and fail." TC-191 ‡ requires four of them reported distinctly. Declared
        // here so a report has six names to use and no excuse for two.
        $categories = UnverifiableRegister::reportCategories();

        self::assertCount(6, $categories);
        self::assertSame(
            ['verified', 'failing', 'not_implemented', 'unverifiable', 'unmeasurable', 'untested_because_undecided'],
            array_keys($categories),
        );

        // TC-194 ‡: unmeasurable contributes to no pass count, which is only
        // possible if it is not the same category as verified.
        self::assertNotSame($categories['verified'], $categories['unmeasurable']);
    }

    public function test_the_undecided_register_is_not_empty(): void
    {
        // TC-186 ‡: presenting a passing suite without §14.3 alongside it would
        // materially misrepresent readiness. An emptied register would be exactly
        // that presentation, arrived at by deletion.
        $undecided = UnverifiableRegister::undecided();

        self::assertCount(6, $undecided);

        foreach ($undecided as $key => $entry) {
            self::assertNotSame('', trim($entry['count']), $key.' states no count.');
            self::assertNotSame('', trim($entry['source']), $key.' names no source.');
            self::assertNotSame('', trim($entry['note']), $key.' says nothing about what it means here.');
        }
    }

    public function test_no_test_exists_for_a_withheld_capability(): void
    {
        // TC-178 ‡: "no test shall be specified for a withheld capability."
        // TC-179 ‡: where a journey reaches one, the test asserts the platform's
        // **refusal** — the specified behaviour — and not an invented outcome.
        //
        // So a test method naming a withheld thing is an offence unless it is
        // named for that thing's absence or refusal. CLAUDE.md §5 lists the
        // withheld areas; CMP-DOC-04 §9.2 records that three of them carry zero
        // functional requirements.
        $offenders = [];

        foreach (self::methodsInTheSuite() as $file => $methods) {
            foreach ($methods as $method) {
                $offence = self::withheldCapabilityIn($method);

                if ($offence !== null) {
                    $offenders[] = $file.'::'.$method.' → '.$offence;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'TC-178 ‡: a test exists for a withheld capability. TC-179 ‡ permits one only where it asserts '
            .'the platform\'s refusal.',
        );
    }

    public function test_the_withheld_detector_reads_a_capability_and_not_a_refusal(): void
    {
        // TC-024 ‡: a false positive is fixed, never disabled — which presumes
        // somebody can tell one from the other. Both directions, asserted.
        //
        // True positives: a test that builds the withheld thing.
        self::assertSame('rating', self::withheldCapabilityIn('test_a_rating_is_recorded_against_a_trip'));
        self::assertSame('wallet', self::withheldCapabilityIn('test_the_wallet_balance_updates'));
        self::assertSame('refund', self::withheldCapabilityIn('test_a_refund_is_issued'));

        // False positives TC-179 ‡ explicitly permits: the platform's refusal, and
        // the thing's recorded absence.
        self::assertNull(self::withheldCapabilityIn('test_no_rating_table_is_created'));
        self::assertNull(self::withheldCapabilityIn('test_a_refund_is_refused_because_gap_009_is_open'));
        self::assertNull(self::withheldCapabilityIn('test_the_wallet_resource_must_not_be_routed'));
        self::assertNull(self::withheldCapabilityIn('test_constraint_18_is_withheld_and_never_enforced'));

        // And a name with nothing withheld in it at all.
        self::assertNull(self::withheldCapabilityIn('test_a_session_is_established'));

        // The false positive the suite itself produced. "demonstrating" contains
        // "rating", and the first version of this detector flagged UserTest over
        // it. TC-024 ‡: fixed by matching whole segments of the name, not by
        // renaming the test or excluding the file.
        self::assertNull(
            self::withheldCapabilityIn('test_demonstrating_a_number_twice_changes_nothing_and_records_nothing'),
        );

        // The plural is the same capability, and must still be caught.
        self::assertSame('rating', self::withheldCapabilityIn('test_ratings_are_averaged'));
        self::assertSame('refund', self::withheldCapabilityIn('test_refunds_are_paid_out'));
    }

    /**
     * The withheld capability a test-method name builds for, or null.
     *
     * Null where the name is about the thing's **absence or refusal**, which
     * `TC-179` ‡ makes the specified behaviour and therefore the right test to
     * have.
     */
    private static function withheldCapabilityIn(string $method): ?string
    {
        $lowered = strtolower($method);

        foreach (UnverifiableRegister::refusalTerms() as $refusal) {
            if (str_contains($lowered, $refusal)) {
                return null;
            }
        }

        // The withheld-term match itself is UnverifiableRegister's, so that this
        // detector and TestDataRulesTest cannot drift apart. What is local here
        // is the TC-179 ‡ exemption above: a name about refusal or absence is the
        // specified behaviour and the right test to have.
        return UnverifiableRegister::withheldTermIn($lowered);
    }

    /**
     * Every public method declared in the suite, by file.
     *
     * Named so that it does not begin with `test`: Pint's `php_unit_method_casing`
     * rule renamed an earlier `testMethodNames()` to `test_method_names()`,
     * taking it for a test method — and PHPUnit then could not find what this
     * one called. `TC-024` ‡ again: the name was fixed, not the rule disabled.
     *
     * Read from the tree rather than from a list, for the reason
     * `ObligationRegisterTest` gives about its own scan: a list is a thing that
     * falls behind, and this exists to catch something falling behind.
     *
     * @return array<string, list<string>>
     */
    private static function methodsInTheSuite(): array
    {
        $found = [];
        $root = dirname(__DIR__);

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents)) {
                continue;
            }

            preg_match_all('/public function (\w+)\s*\(/', $contents, $matches);

            /** @var list<non-empty-string> $methods */
            $methods = $matches[1];

            if ($methods !== []) {
                $found[str_replace($root.DIRECTORY_SEPARATOR, '', $file->getPathname())] = $methods;
            }
        }

        self::assertNotSame([], $found, 'The scan found no tests, so it proved nothing.');

        return $found;
    }
}
