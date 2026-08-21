<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Tests\ObligationRegister;
use Tests\UnverifiableRegister;
use Tests\VerificationReport;

/**
 * CMP-DOC-18 §15 — the report, and the four things it must never do.
 *
 * `TC-191` ‡, `TC-193` ‡, `TC-194` ‡ and `TC-195` ‡ are all statements about a
 * **report** rather than about the platform, which makes them the easiest kind of
 * obligation to declare discharged and never check. This checks them.
 */
final class VerificationReportTest extends TestCase
{
    public function test_the_report_carries_all_six_categories_and_never_fewer(): void
    {
        // TC-193 ‡: "the six categories above shall never be collapsed into pass
        // and fail." TC-191 ‡ requires four of them distinct by name.
        $categories = VerificationReport::assemble()['categories'];

        self::assertSame(
            array_keys(UnverifiableRegister::reportCategories()),
            array_keys($categories),
            'TC-193 ‡: a category has been dropped or renamed.',
        );

        foreach (['verified', 'unverifiable', 'unmeasurable', 'untested_because_undecided'] as $distinct) {
            self::assertArrayHasKey($distinct, $categories, 'TC-191 ‡: '.$distinct.' is reported distinctly.');
        }
    }

    public function test_every_obligation_is_counted_exactly_once(): void
    {
        // A report that lost one would understate what is outstanding, and one
        // that counted one twice would overstate what is done. Both are ways of
        // being wrong that a total conceals.
        $categories = VerificationReport::assemble()['categories'];

        $fromObligations = $categories['verified']
            + $categories['failing']
            + $categories['not_implemented']
            + $categories['untested_because_undecided'];

        // The 99, plus §14.3's six withheld areas which are counted into the same
        // category and are not obligations.
        self::assertSame(
            count(ObligationRegister::all()) + count(UnverifiableRegister::undecided()),
            $fromObligations,
        );
    }

    public function test_an_unmeasurable_result_contributes_to_no_pass_count(): void
    {
        // TC-194 ‡, verbatim: "unmeasurable results shall not contribute to any
        // pass count." The shape is what makes it hold — unmeasurable is its own
        // category, and nothing sums it into verified.
        $categories = VerificationReport::assemble()['categories'];

        self::assertSame(count(UnverifiableRegister::unmeasurable()), $categories['unmeasurable']);

        // And `verified` is exactly the enforced obligations — no more.
        $enforced = array_filter(
            ObligationRegister::all(),
            static fn (array $obligation): bool => $obligation['status'] === ObligationRegister::ENFORCED,
        );

        self::assertSame(count($enforced), $categories['verified']);
    }

    public function test_the_report_carries_section_14_3_beside_its_figures(): void
    {
        // TC-195 ‡: "a release report shall carry §14.3 alongside its pass
        // figures." TC-186 ‡ is why: presenting a passing suite without it would
        // materially misrepresent readiness.
        $report = VerificationReport::assemble();

        self::assertSame(
            array_keys(UnverifiableRegister::undecided()),
            array_keys($report['undecided']),
        );

        // In the rendered text too, which is what somebody actually reads.
        $rendered = VerificationReport::render();

        self::assertStringContainsString('Untested because undecided', $rendered);
        self::assertStringContainsString('read this beside the figures', $rendered);
    }

    public function test_the_report_states_no_coverage_figure(): void
    {
        // TC-020: "reporting a coverage figure before TC-017 is complete would
        // overstate what is verified, and no such figure appears in this
        // document." TC-012 and TC-190 keep line and branch coverage diagnostic.
        $report = VerificationReport::assemble();

        self::assertNull($report['coverage']);

        $rendered = VerificationReport::render();

        // No percentage anywhere. A report that grew one would be the thing
        // TC-020 forbids, arriving as a convenience.
        self::assertDoesNotMatchRegularExpression('/\d+(?:\.\d+)?\s*%/', $rendered);
    }

    public function test_the_report_does_not_claim_the_gate_is_met(): void
    {
        // TC-200 ‡ admits no exception, and thirteen of the twenty-five do not
        // pass. A report claiming otherwise would be the misrepresentation
        // TC-186 ‡ names — and if this ever fails because the gate genuinely is
        // met, that is a claim to check obligation by obligation rather than to
        // celebrate.
        $gate = VerificationReport::assemble()['gate'];

        self::assertFalse(
            $gate['met'],
            'The report says gate 4 is met. Verify each of the twenty-five against its proving test before '
            .'believing it.',
        );
        self::assertNotSame([], $gate['outstanding']);

        foreach ($gate['outstanding'] as $outstanding) {
            self::assertMatchesRegularExpression('/\((?:absent|blocked|withheld|not_applicable)\)$/', $outstanding);
        }
    }

    public function test_the_report_states_that_no_suppression_is_in_force(): void
    {
        // TC-192: "suppressions in force shall be reported with their
        // justification and their accepting person." There are none, because
        // TC-021 ‡ leaves nowhere to declare one — and reporting that is not the
        // same as reporting nothing.
        $report = VerificationReport::assemble();

        self::assertSame([], $report['suppressions']);
        self::assertStringContainsString('no suppression facility exists', VerificationReport::render());
    }

    public function test_the_rendered_report_names_the_gate_criterion(): void
    {
        // TC-201 leaves what else constitutes a release gate to the Project
        // Owner. The report states the one criterion that exists and invents no
        // second, so a reader can see what "met" would mean.
        $rendered = VerificationReport::render();

        self::assertStringContainsString('TC-200', $rendered);
        self::assertStringContainsString('every non-suppressible obligation passing', $rendered);
        self::assertStringContainsString('met: NO', $rendered);
    }
}
