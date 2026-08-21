<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Infrastructure\Laravel\Providers\AuthorisationServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\DocumentObligations;
use Tests\ObligationRegister;

/**
 * CMP-DOC-18 §17.2 — the thirteen obligations placed directly on that document.
 *
 * §17.2 concludes *"all thirteen are discharged"*, and that is true **of the
 * document**: each names a `TC-` statement, and CMP-DOC-18 states it. Whether the
 * platform implements that statement is a different question, and one §17.2 does
 * not ask.
 *
 * This file asks it, and holds three lines that a hand-maintained register would
 * not:
 *
 * 1. **Every cited `TC-` statement is declared in CMP-DOC-18.** Read from the
 *    document, not from a copy — a register citing a statement nobody wrote would
 *    be asserting its own completeness.
 * 2. **An obligation may claim `ENFORCED` only by naming a class that exists**, and
 *    a non-enforced one must say what stands in the way and name nothing.
 * 3. **`TC-116` ‡ is checked rather than claimed**: every operation the platform's
 *    authorisation policy states has a negative case, and the named method is
 *    resolved by reflection.
 *
 * `TR-121` is the reason all three matter: *"a passing suite is not readiness."*
 */
final class DocumentObligationsTest extends TestCase
{
    public function test_there_are_thirteen(): void
    {
        // §17.2, verbatim: "six in their Obligations This Document Places on
        // Others section, and CMP-DOC-13 in a section of its own devoted to this
        // document. Ten plus three is thirteen."
        $obligations = DocumentObligations::all();

        self::assertCount(13, $obligations);
        self::assertSame(range(1, 13), array_keys($obligations));
    }

    public function test_every_cited_statement_is_declared_in_the_document(): void
    {
        // The assertion this file exists for. A register that cited TC-999 would
        // otherwise look exactly like one that cited TC-143, and an obligation
        // pointing at a statement nobody wrote is an obligation nobody discharged.
        $document = self::document();
        $missing = [];

        foreach (DocumentObligations::all() as $n => $obligation) {
            foreach ($obligation['dischargedBy'] as $statement) {
                // The declaration form CMP-DOC-18 uses throughout: a table row
                // opening with the identifier in backticks. Appendix A's index
                // repeats some of them, which is why this looks for a row rather
                // than for the bare string.
                if (preg_match('/^\| `'.preg_quote($statement, '/').'` /m', $document) !== 1) {
                    $missing[] = 'obligation '.$n.' cites '.$statement;
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'CMP-DOC-18 declares no such statement. The register must expose that rather than treat the '
            .'obligation as complete.',
        );
    }

    public function test_the_citation_check_would_notice_a_statement_that_does_not_exist(): void
    {
        // TC-024 ‡: a detector nobody validated is one nobody can trust when it
        // fires. TC-143 is declared; TC-999 is not, and the check must tell them
        // apart on the real document.
        $document = self::document();

        self::assertSame(1, preg_match('/^\| `TC-143` /m', $document));
        self::assertSame(0, preg_match('/^\| `TC-999` /m', $document));

        // And a statement mentioned only in prose is not a declaration. TC-017 is
        // declared as a row; a bare mention inside another statement's text is
        // not what this looks for.
        self::assertStringContainsString('TC-017', $document);
        self::assertSame(1, preg_match('/^\| `TC-017` /m', $document));
    }

    public function test_an_enforced_obligation_names_a_class_that_exists(): void
    {
        // The same discipline ObligationRegister uses: a status maintained by
        // hand drifts, and the fix is to make the claim checkable.
        foreach (DocumentObligations::all() as $n => $obligation) {
            if ($obligation['status'] !== ObligationRegister::ENFORCED) {
                continue;
            }

            $provenBy = $obligation['provenBy'];

            self::assertIsString($provenBy, 'Obligation '.$n.' claims enforcement and names nothing.');
            self::assertTrue(class_exists($provenBy), 'Obligation '.$n.' names '.$provenBy.', which is gone.');
        }
    }

    public function test_an_obligation_that_is_not_discharged_says_what_stands_in_the_way(): void
    {
        // TADR-12: "unverifiable is recorded, never padded." And the instruction
        // this register was built under: an obligation is not discharged merely
        // because a related test exists — so a blocked one naming a proving test
        // is a contradiction rather than a nuance.
        foreach (DocumentObligations::all() as $n => $obligation) {
            if ($obligation['status'] === ObligationRegister::ENFORCED) {
                continue;
            }

            self::assertNotSame('', trim($obligation['note']), 'Obligation '.$n.' does not say why.');
            self::assertNull(
                $obligation['provenBy'],
                'Obligation '.$n.' is '.$obligation['status'].' and names a test that proves it.',
            );
        }
    }

    public function test_every_status_is_one_of_the_five(): void
    {
        // The vocabulary CC-038 recorded, used unchanged. A sixth invented for an
        // awkward entry is how a register stops meaning anything.
        $statuses = [
            ObligationRegister::ENFORCED,
            ObligationRegister::ABSENT,
            ObligationRegister::BLOCKED,
            ObligationRegister::WITHHELD,
            ObligationRegister::NOT_APPLICABLE,
        ];

        foreach (DocumentObligations::all() as $n => $obligation) {
            self::assertContains($obligation['status'], $statuses, 'Obligation '.$n.' carries an undeclared status.');
            self::assertNotSame('', trim($obligation['requirement']), 'Obligation '.$n.' names no requirement.');
            self::assertNotSame('', trim($obligation['source']), 'Obligation '.$n.' names no source.');
            self::assertNotSame([], $obligation['dischargedBy'], 'Obligation '.$n.' cites no TC- statement.');
        }
    }

    public function test_every_operation_has_a_negative_authorisation_case(): void
    {
        // TC-116 ‡ / SEC-236: "negative authorisation cases shall exist for every
        // operation, not a sample." Checked against the platform's own policy, so
        // an operation added without one fails the build — which is the only way
        // "every" survives the next operation.
        $declared = DocumentObligations::negativeAuthorisationCases();
        $missing = [];

        foreach (array_keys(AuthorisationServiceProvider::sessionRules()) as $operation) {
            if (! array_key_exists($operation, $declared)) {
                $missing[] = $operation;
            }
        }

        self::assertSame(
            [],
            $missing,
            'SEC-236: an operation has no negative authorisation case. Two out of three is a sample.',
        );

        // And no case is declared for an operation the policy does not state —
        // which would mean the map had outlived its operation.
        self::assertSame(
            array_keys(AuthorisationServiceProvider::sessionRules()),
            array_keys($declared),
        );
    }

    public function test_every_declared_negative_case_resolves_to_a_real_test(): void
    {
        // A map of names is a map of intentions until something resolves them.
        // Reflection rather than a string search: a method renamed in the test
        // and not here would otherwise keep passing.
        foreach (DocumentObligations::negativeAuthorisationCases() as $operation => [$class, $method]) {
            self::assertTrue(class_exists($class), $operation.' names '.$class.', which does not exist.');

            $reflection = new ReflectionClass($class);

            self::assertTrue(
                $reflection->hasMethod($method),
                $operation.': '.$class.' has no '.$method.'(). SEC-236 needs the case, not the intention.',
            );
        }
    }

    public function test_no_obligation_anywhere_is_discharged_by_penetration_testing(): void
    {
        // TC-121 / SEC-237: "penetration testing shall not be treated as a
        // substitute for any check here." Discharged by absence, and checked as
        // such across both registers — a check whose proof was a pen test would
        // be a check nobody runs.
        //
        // The detector looks for a **substitution claim**, not for the word. Its
        // first version matched "penetration" anywhere and flagged obligation 6,
        // whose entire subject is penetration testing — TC-024 ‡ required that to
        // be fixed rather than excluded, and the fix is to check the thing that
        // could carry such a claim.
        $offenders = [];

        foreach (ObligationRegister::all() as $reference => $obligation) {
            if (self::claimsPenetrationTestingAsProof($obligation['provenBy'], $obligation['note'])) {
                $offenders[] = $reference;
            }
        }

        foreach (DocumentObligations::all() as $n => $obligation) {
            if (self::claimsPenetrationTestingAsProof($obligation['provenBy'], $obligation['note'])) {
                $offenders[] = '§17.2/'.$n;
            }
        }

        self::assertSame([], $offenders, 'TC-121 / SEC-237: penetration testing is not a substitute for a check.');
    }

    public function test_the_document_is_the_one_cmp_doc_18_lives_in(): void
    {
        // A check reading the wrong file would pass for the wrong reason. The
        // document identifies itself, and CLAUDE.md §3 makes the .md the
        // authority — the .docx is a generated presentation and is never read.
        $path = DocumentObligations::documentPath();

        self::assertFileExists($path);
        self::assertStringEndsWith('.md', $path);
        self::assertStringContainsString('CMP-DOC-18 — Testing & QA Documentation', self::document());
    }

    public function test_the_substitution_detector_reads_a_claim_and_not_a_subject(): void
    {
        // TC-024 ‡, on the detector above. Obligation 6's entire subject is
        // penetration testing — its note says the platform does **not** treat it
        // as a substitute — and a word-level match flagged it. Fixed by looking
        // for the claim rather than for the word.
        //
        // The claims that must be caught.
        foreach ([
            'Covered by penetration testing.',
            'Proven by a penetration test.',
            'Verified by pentest in the last cycle.',
            'A penetration test covers this.',
        ] as $claim) {
            self::assertTrue(
                self::claimsPenetrationTestingAsProof(null, $claim),
                '"'.$claim.'" is a substitution claim and the detector missed it.',
            );
        }

        // And the prose that must not be. Every one of these is a statement that
        // penetration testing does *not* discharge anything.
        foreach ([
            'TC-121 / SEC-237: penetration testing is not a substitute for any check.',
            'Penetration testing scope and cadence are [TBD – Business Decision Required].',
            'No claim of having been penetration tested follows from this document.',
        ] as $prose) {
            self::assertFalse(
                self::claimsPenetrationTestingAsProof(null, $prose),
                '"'.$prose.'" says the opposite and the detector flagged it.',
            );
        }

        // A class name naming a pen test would be a claim wherever it appeared.
        self::assertTrue(self::claimsPenetrationTestingAsProof('Tests\PenetrationTest', ''));
    }

    /**
     * Whether an entry claims a penetration test as what discharges it.
     *
     * `provenBy` is the field that names a proof, so a penetration test appearing
     * there is a claim however it is written. A **note** is prose, and prose about
     * penetration testing is usually — as in obligation 6 — a statement that it
     * discharges nothing; only a claim of proof counts.
     */
    private static function claimsPenetrationTestingAsProof(?string $provenBy, string $note): bool
    {
        // The separator is optional, so PascalCase matches: a class called
        // PenetrationTest names a proof as plainly as the words 'penetration
        // test' do, and the first version of this pattern required a space and
        // missed it. TC-024 ‡ — its own validation caught it.
        $tool = '(?:penetration[ _-]?test(?:ing|s|ed)?|pen[ _-]?test(?:ing|s|ed)?)';

        if ($provenBy !== null && preg_match('/'.$tool.'/i', $provenBy) === 1) {
            return true;
        }

        // "by <tool>" or "<tool> covers/proves/verifies" — a claim. Guarded
        // against "not a substitute", "no claim", and the other denials, because
        // a denial that mentions the tool is the opposite of a claim.
        if (preg_match('/\b(?:not|never|no)\b[^.]{0,60}'.$tool.'/i', $note) === 1) {
            return false;
        }

        if (preg_match('/'.$tool.'[^.]{0,40}\b(?:is not|are not)\b/i', $note) === 1) {
            return false;
        }

        return preg_match('/\b(?:covered|proven|proved|verified|discharged|satisfied)\s+by\s+(?:a\s+|the\s+)?'.$tool.'/i', $note) === 1
            || preg_match('/'.$tool.'\s+(?:covers|proves|verifies|discharges|satisfies)\b/i', $note) === 1;
    }

    private static function document(): string
    {
        $contents = file_get_contents(DocumentObligations::documentPath());

        self::assertIsString($contents, 'CMP-DOC-18 could not be read, so nothing here proved anything.');

        return $contents;
    }
}
