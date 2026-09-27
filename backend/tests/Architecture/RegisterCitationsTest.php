<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\DocumentObligations;
use Tests\InterfaceObligations;
use Tests\ObligationRegister;

/**
 * Every test method a register names exists.
 *
 * The three registers are where this platform states what is verified. `TC-009`
 * leaves status *"maintained"*, and `CC-038` answered that with `provenBy`: an
 * entry may claim `enforced` only by naming a **class** that exists, which
 * `ObligationRegisterTest`, `InterfaceObligationsTest` and
 * `DocumentObligationsTest` each assert of their own register.
 *
 * A class is the coarse half. The notes name **methods** — the specific test that
 * proves the specific statement — and nothing checked those. A renamed method
 * leaves the class existing and the citation pointing at nothing, and the register
 * goes on reading as though the obligation were discharged by something a reader
 * could go and look at.
 *
 * ## Why the method is not bound to `provenBy`'s class
 *
 * Because it is often not in it, legitimately. `API-037` ‡ is proven by
 * `StructuralRulesTest`'s rule 11 **and** by
 * `RequestSchemaTest::test_the_register_covers_all_seven_of_api_037`; `API-053` ‡
 * by a column check in one class and a fixture check in another. `provenBy` holds
 * one class and the note names the rest, which is the shape the registers already
 * use. `API-170` ‡'s note goes further and names the method its citation was
 * **corrected from** (`CC-056`), which is history rather than a claim.
 *
 * So the assertion is the one that is true of all three shapes and false of a
 * broken citation: a method a register names is a method the suite has. `CC-057`
 * records it, and found no broken citation — which is the point of adding it before
 * one appears rather than after.
 */
final class RegisterCitationsTest extends TestCase
{
    public function test_every_test_method_a_register_names_exists(): void
    {
        $methods = self::methodsInTheSuite();
        $missing = [];

        foreach (self::citedMethods() as $where => $cited) {
            foreach ($cited as $method) {
                if (! in_array($method, $methods, true)) {
                    $missing[] = $where.' → '.$method.'()';
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'A register names a test method that the suite does not have. Either the method was renamed and the '
            .'citation is now pointing at nothing, or the test was removed and the entry is claiming an '
            .'enforcement that no longer exists. TC-009 makes status something maintained; this is what keeps it '
            .'from being maintained in name only.',
        );
    }

    /**
     * `TC-024` ‡ — the reader finds the citations, and would report one that broke.
     */
    public function test_the_citation_reader_finds_what_the_registers_name(): void
    {
        $cited = self::citedMethods();

        // One anchor per register, so that a reader returning nothing for any of
        // the three fails here rather than passing the rule above vacuously.
        self::assertContains(
            'test_a_deployment_serving_only_safety_records_an_incident',
            $cited['InterfaceObligations'],
            'CC-056 put this method in API-170 ‡\'s note.',
        );

        // The other two registers name no method **in prose**, and that is their
        // design rather than an omission. `CC-038` made `provenBy` a class, and
        // `ObligationRegister`'s 99 are obligations on the platform discharged by a
        // rule or a whole suite rather than by one assertion.
        // `DocumentObligations` does cite methods, in a **structured** field —
        // `negativeAuthorisationCases()` — which `DocumentObligationsTest` already
        // checks by reflection, and which is the better shape for exactly the
        // reason this rule exists.
        //
        // Stated rather than left open, so that the day an entry does put a method
        // in a note, it is read — and so that a reader silently returning nothing
        // is not mistaken for a register that names nothing.
        self::assertSame([], $cited['ObligationRegister']);
        self::assertSame([], $cited['DocumentObligations']);

        // And the suite reader finds this file's own methods, so "not in the suite"
        // means what it says.
        $methods = self::methodsInTheSuite();

        self::assertContains('test_every_test_method_a_register_names_exists', $methods);
        self::assertNotContains('test_a_method_nobody_ever_wrote', $methods);
    }

    /**
     * Every `test_…` name any register note mentions, by register.
     *
     * @return array<string, list<string>>
     */
    private static function citedMethods(): array
    {
        $cited = ['InterfaceObligations' => [], 'ObligationRegister' => [], 'DocumentObligations' => []];

        foreach (InterfaceObligations::all() as $entry) {
            $cited['InterfaceObligations'] = [...$cited['InterfaceObligations'], ...self::methodsNamedIn($entry['note'])];
        }

        foreach (ObligationRegister::all() as $entry) {
            $cited['ObligationRegister'] = [...$cited['ObligationRegister'], ...self::methodsNamedIn($entry['note'])];
        }

        foreach (DocumentObligations::all() as $entry) {
            $cited['DocumentObligations'] = [...$cited['DocumentObligations'], ...self::methodsNamedIn($entry['note'])];
        }

        return $cited;
    }

    /**
     * @return list<string>
     */
    private static function methodsNamedIn(string $note): array
    {
        preg_match_all('/\b(test_[a-z0-9_]+)\b/', $note, $matches);

        /** @var list<string> $found */
        $found = array_values(array_unique($matches[1]));

        return $found;
    }

    /**
     * Every test method the suite declares.
     *
     * Read from the files rather than from loaded classes, for `CC-054`'s reason: a
     * class PHP has not loaded is not declared, and which classes a run has loaded
     * depends on the run.
     *
     * @return list<string>
     */
    private static function methodsInTheSuite(): array
    {
        $methods = [];
        $root = dirname(__DIR__, 2).'/tests';

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            self::assertIsString($contents);

            preg_match_all('/function (test_[a-z0-9_]+)\s*\(/', $contents, $matches);

            foreach ($matches[1] as $method) {
                $methods[] = $method;
            }
        }

        return array_values(array_unique($methods));
    }
}
