<?php

declare(strict_types=1);

namespace Tests;

/**
 * The verification report — CMP-DOC-18 §15.
 *
 * Three registers now hold what is verified, what cannot be, and what is
 * discharged. This assembles them into the report §15.2 specifies, in the six
 * categories `TC-193` ‡ forbids collapsing.
 *
 * ## Why this lives in `tests/` and not in `src/`
 *
 * `TC-195` ‡ calls it a **release report**, and a release report is an artefact
 * about the build rather than a capability of the platform. The registers it
 * reads are test infrastructure, `composer.json` keeps `tests/` out of the
 * production classmap, and a console command in `src/` that could read them would
 * be a production surface reaching into the suite.
 *
 * ## What it will not do
 *
 * **No coverage figure.** `TC-020`: *"reporting a coverage figure before
 * `TC-017` is complete would overstate what is verified"*, and `TC-017`'s
 * statement-by-statement mapping of 1,164 integrity-critical statements has not
 * been done. `TC-012` and `TC-190` add that line and branch coverage carry no
 * threshold and are diagnostic only.
 *
 * **No pass count that includes an unmeasurable result.** `TC-194` ‡ is explicit,
 * and the shape below is what makes it hold: `unmeasurable` is its own category
 * and nothing sums it into `verified`.
 *
 * **No claim that the gate is met.** `TC-200` ‡ requires every non-suppressible
 * obligation passing, and thirteen of the twenty-five do not. The report says so
 * rather than reporting a percentage that reads like progress.
 */
final class VerificationReport
{
    /**
     * The report, in §15.2's six categories plus what `TC-195` ‡ requires beside
     * them.
     *
     * @return array{
     *     categories: array<string, int>,
     *     gate: array{criterion: string, met: bool, outstanding: list<string>},
     *     unverifiable: list<string>,
     *     unmeasurable: list<string>,
     *     undecided: array<string, string>,
     *     suppressions: list<string>,
     *     coverage: null,
     * }
     */
    public static function assemble(): array
    {
        return [
            // TC-189: obligation status, reported per obligation, totalled here.
            // TC-193 ‡: six categories, never collapsed into pass and fail.
            'categories' => self::categories(),

            // TC-200 ‡ / TC-195 ‡: the gate criterion, whether it is met, and what
            // holds it shut. Never a percentage — thirteen blocked obligations do
            // not average into anything useful.
            'gate' => self::gate(),

            // §14.2 — TC-175, with compensating controls recorded in the register.
            'unverifiable' => array_keys(UnverifiableRegister::needingJudgement()),

            // §15.3 — TC-196 ‡: measured, never reported as a passing test.
            'unmeasurable' => array_keys(UnverifiableRegister::unmeasurable()),

            // §14.3 — TC-186 ‡: carried alongside the pass figures, because a
            // passing suite without this misrepresents readiness.
            'undecided' => self::undecided(),

            // TC-192: suppressions in force, with their justification and the
            // person accepting them. There is no suppression facility at all
            // (TC-021 ‡), so this is empty and ObligationRegisterTest is what
            // keeps it that way.
            'suppressions' => [],

            // TC-020 / TC-012 / TC-190. Null, and null on purpose.
            'coverage' => null,
        ];
    }

    /**
     * The six categories of §15.2, counted over the 99.
     *
     * `not_implemented` splits into the register's `absent` and `blocked`
     * because `CC-038` recorded that the two are different facts — one is work,
     * the other is somebody's decision — and a report that merged them would put
     * the distinction back where it was.
     *
     * @return array<string, int>
     */
    public static function categories(): array
    {
        $counts = array_fill_keys(array_keys(UnverifiableRegister::reportCategories()), 0);

        foreach (ObligationRegister::all() as $obligation) {
            $counts[match ($obligation['status']) {
                // "Verified" is an obligation that exists, is implemented and
                // passes. The suite having just run is what makes the third true.
                ObligationRegister::ENFORCED => 'verified',
                ObligationRegister::ABSENT, ObligationRegister::BLOCKED => 'not_implemented',
                // TADR-13: a withheld capability is untested because undecided,
                // and must never move to any other category.
                ObligationRegister::WITHHELD => 'untested_because_undecided',
                default => 'not_implemented',
            }]++;
        }

        // The three §14.2 properties and the five §15.3 categories are their own,
        // and are never obligations — TC-174 ‡ forbids giving them a token test,
        // so they can only ever be counted here.
        $counts['unverifiable'] = count(UnverifiableRegister::needingJudgement());
        $counts['unmeasurable'] = count(UnverifiableRegister::unmeasurable());
        $counts['untested_because_undecided'] += count(UnverifiableRegister::undecided());

        return $counts;
    }

    /**
     * `TC-200` ‡ — gate 4, and what holds it shut.
     *
     * @return array{criterion: string, met: bool, outstanding: list<string>}
     */
    public static function gate(): array
    {
        $outstanding = [];

        foreach (ObligationRegister::all() as $reference => $obligation) {
            if ($obligation['suppressible'] === false && $obligation['status'] !== ObligationRegister::ENFORCED) {
                $outstanding[] = $reference.' ('.$obligation['status'].')';
            }
        }

        return [
            // TC-201 records that what else constitutes a release gate is
            // [TBD – Business Decision Required]. This is the one criterion that
            // exists, and the report states no second.
            'criterion' => 'TC-200 ‡: every non-suppressible obligation passing, with no exception.',
            'met' => $outstanding === [],
            'outstanding' => $outstanding,
        ];
    }

    /**
     * §14.3, as the report carries it (`TC-185`, `TC-186` ‡).
     *
     * @return array<string, string>
     */
    private static function undecided(): array
    {
        $carried = [];

        foreach (UnverifiableRegister::undecided() as $key => $entry) {
            $carried[$key] = $entry['count'].' — '.$entry['source'];
        }

        return $carried;
    }

    /**
     * The report as text, for a human reading a build.
     *
     * `TC-185` distinguishes untested-because-undecided from
     * untested-because-unfinished, and the layout keeps them apart on the page
     * rather than only in the data.
     */
    public static function render(): string
    {
        $report = self::assemble();
        $lines = ['CMP verification report — CMP-DOC-18 §15.2', ''];

        $lines[] = 'Categories (TC-193 ‡: never collapsed into pass and fail)';

        foreach ($report['categories'] as $category => $count) {
            $lines[] = sprintf('  %-30s %d', $category, $count);
        }

        $lines[] = '';
        $lines[] = 'Release gate (TC-200 ‡)';
        $lines[] = '  '.$report['gate']['criterion'];
        $lines[] = '  met: '.($report['gate']['met'] ? 'yes' : 'NO');

        foreach ($report['gate']['outstanding'] as $outstanding) {
            $lines[] = '    outstanding: '.$outstanding;
        }

        $lines[] = '';
        $lines[] = 'Unverifiable — §14.2, with compensating controls';

        foreach ($report['unverifiable'] as $statement) {
            $lines[] = '  '.$statement;
        }

        $lines[] = '';
        $lines[] = 'Unmeasurable — §15.3, measured with no target (TC-196 ‡)';

        foreach ($report['unmeasurable'] as $category) {
            $lines[] = '  '.$category;
        }

        $lines[] = '';
        $lines[] = 'Untested because undecided — §14.3 (TC-186 ‡: read this beside the figures)';

        foreach ($report['undecided'] as $key => $summary) {
            $lines[] = sprintf('  %-24s %s', $key, $summary);
        }

        $lines[] = '';
        $lines[] = 'Suppressions in force (TC-192): '
            .($report['suppressions'] === [] ? 'none — no suppression facility exists (TC-021 ‡)' : 'SEE ABOVE');
        $lines[] = 'Coverage figure (TC-020): none — TC-017\'s mapping is not complete.';

        return implode("\n", $lines)."\n";
    }
}
