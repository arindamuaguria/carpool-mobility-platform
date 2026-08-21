<?php

declare(strict_types=1);

namespace Tests;

/**
 * What cannot be tested, and what cannot be pass-or-fail — CMP-DOC-18 §14 and
 * §15.3.
 *
 * {@see ObligationRegister} says which of the 99 obligations hold. This says what
 * no obligation could ever hold, and why. The two are deliberately separate
 * files: `TC-173` requires these categories to be *"reported separately from
 * verified and failed"*, and a category kept in the same list as the pass count
 * is a category that ends up inside it.
 *
 * ## `TC-174` ‡ — nothing here gets a token test
 *
 * *"None shall be given a token test to make a report look complete."* That is
 * the rule this register exists to make keepable. Each entry names its
 * compensating control or its measurement instead, and `UnverifiableRegisterTest`
 * fails the build if any entry ever acquires a proving test — because the moment
 * one does, it belongs in the obligation register and not here.
 *
 * ## `TC-186` ‡ — why this is a file and not a paragraph
 *
 * *"Presenting a passing suite without §14.3 alongside it would materially
 * misrepresent the product's readiness."* `TC-181` ‡ puts it plainly: **a fully
 * passing suite leaves the largest known risks in this product untouched**,
 * because they are undecided rather than defective. A green build says nothing
 * about {@see undecided()}, and this register is what stands beside it.
 */
final class UnverifiableRegister
{
    /**
     * `TC-191` ‡ and §15.2's six report categories.
     *
     * `TC-193` ‡: *"the six categories above shall never be collapsed into pass
     * and fail."* Declared here so that a report has six names to use and no
     * excuse for two.
     *
     * @return array<string, string>
     */
    public static function reportCategories(): array
    {
        return [
            'verified' => 'An obligation exists, is implemented, and passes.',
            'failing' => 'An obligation exists, is implemented, and does not pass.',
            'not_implemented' => 'An obligation exists and has not been built.',
            'unverifiable' => 'CMP-DOC-18 §14.2 — a property needing judgement, with a compensating control.',
            'unmeasurable' => 'CMP-DOC-18 §15.3 — measured, with no target to fail against.',
            'untested_because_undecided' => 'CMP-DOC-18 §14.3 — no behaviour exists to test.',
        ];
    }

    /**
     * §14.2 — properties needing human judgement or outside observation.
     *
     * **Inherited unchanged from CMP-DOC-13 §19.4**, which CMP-DOC-18 states in
     * terms: *"this document adds none."* Nothing is added here either.
     * `TC-176` is the only route in — a ‡ statement that `TC-017`'s mapping finds
     * genuinely unverifiable — and `TC-017` has not been done.
     *
     * @return array<string, array{
     *     property: string,
     *     whyUnverifiable: string,
     *     compensatingControl: string,
     *     becomesVerifiableWhen: ?string,
     * }>
     */
    public static function needingJudgement(): array
    {
        return [
            'SEC-089' => [
                'property' => 'Personal data is minimised to a stated purpose.',
                'whyUnverifiable' => 'Requires judgement about whether a purpose is legitimate. Nothing '
                    .'automated can read a purpose and say whether it justifies a field.',
                'compensatingControl' => 'SEC-090: an element with no stated purpose is a review finding. '
                    .'BAD-RULE-043 applies it to the one table that holds personal data — op_users carries '
                    .'the verified phone number and nothing else, and the migration records that no name, '
                    .'email address, date of birth or address may be added on the ground that it may be useful.',
                // TC-177: "the other two do not."
                'becomesVerifiableWhen' => null,
            ],
            'SEC-142' => [
                'property' => 'The payment provider handles payment instruments correctly.',
                'whyUnverifiable' => 'Outside the platform\'s observation. The platform can see what it sends '
                    .'and what comes back, and nothing about what happens between.',
                'compensatingControl' => 'PAY-188: verified per provider as a selection gate, before the '
                    .'provider is chosen rather than after. BAD-DEP-004 leaves the PSP unselected, so the gate '
                    .'has not been applied to anybody yet.',
                'becomesVerifiableWhen' => null,
            ],
            'SEC-070' => [
                'property' => 'Disclosure scopes are bounded by a business decision.',
                'whyUnverifiable' => 'The decision does not exist, so there is nothing to check against. This '
                    .'is not a property that resists observation — it is a property with no stated value.',
                'compensatingControl' => 'None that verifies it. NFR-066 is unresolved and NOTIF-164 records '
                    .'the same gap from the notification side. FRD-GAP-001 is the functional-requirement half.',
                // TC-177, verbatim: "SEC-070 becomes verifiable when BAD-DEC-022
                // is decided; the other two do not."
                'becomesVerifiableWhen' => 'BAD-DEC-022',
            ],
        ];
    }

    /**
     * §15.3 — five categories with no target to fail against.
     *
     * `BAD-DEC-018` leaves 69 quality targets unset and launch scale is unstated,
     * so each of these is **measured** and reported as measurement.
     *
     * `TC-199` is the rule that makes this register worth having: *"no provisional
     * threshold shall be invented to make these categories pass or fail."* The
     * `target` field is `null` for every entry and
     * `UnverifiableRegisterTest` fails the build if one ever holds a figure —
     * which is what inventing a threshold would look like.
     *
     * @return array<string, array{
     *     category: string,
     *     unsetTarget: string,
     *     measuredInstead: string,
     *     target: null,
     * }>
     */
    public static function unmeasurable(): array
    {
        return [
            'performance' => [
                'category' => 'Performance and latency',
                'unsetTarget' => 'NFR-001 to NFR-025',
                'measuredInstead' => 'Observed times per operation.',
                'target' => null,
            ],
            'load' => [
                'category' => 'Load and capacity',
                'unsetTarget' => 'NFR-041 to NFR-052, and launch scale',
                'measuredInstead' => 'Observed throughput and contention. TC-149 places DB-091\'s lock-wait '
                    .'bound here too — ConcurrentIdempotencyClaimTest measures the machine rather than '
                    .'asserting a figure, for exactly this reason.',
                'target' => null,
            ],
            'availability' => [
                'category' => 'Availability and recovery',
                'unsetTarget' => 'NFR-036 to NFR-039, RPO and RTO',
                'measuredInstead' => 'Observed recovery behaviour. BAD-DEP-009 has selected no hosting, so '
                    .'there is nothing yet to observe.',
                'target' => null,
            ],
            'client_resource' => [
                'category' => 'Client resource consumption',
                'unsetTarget' => 'NFR-151 to NFR-158, all unset',
                'measuredInstead' => 'The nine measurement points of CMP-DOC-08 §13.1. Mobile/ is empty and '
                    .'CMP-IMP-010 is blocked on MOB-OQ-001, so none is instrumented.',
                'target' => null,
            ],
            'third_party_cost' => [
                'category' => 'Third-party cost',
                'unsetTarget' => 'NFR-145, NFR-149',
                'measuredInstead' => 'Cost per trip and per search. No provider is bound, so no call is made '
                    .'and no cost is incurred.',
                'target' => null,
            ],
        ];
    }

    /**
     * §14.3 — behaviour that is undecided, and therefore has nothing to test.
     *
     * `TC-178` ‡: *"no test shall be specified for a withheld capability."*
     * `TC-179` ‡: where a journey reaches one, the test asserts **the platform's
     * refusal** — the specified behaviour — and not an invented outcome.
     * `TC-180`: no pending or skipped test is created as a placeholder.
     *
     * The counts are CMP-DOC-18's, restated so that a reader of a green build has
     * the scale of what it does not cover in front of them (`TC-186` ‡).
     *
     * @return array<string, array{withheld: string, count: string, source: string, note: string}>
     */
    public static function undecided(): array
    {
        return [
            'api_resources' => [
                'withheld' => 'API resources',
                'count' => '11',
                'source' => 'CMP-DOC-10 §11.14',
                'note' => 'routes/api.php declares none of them, and its own note records that nothing is '
                    .'stubbed for any — ADM-187/ADM-191 forbid a placeholder for a withheld item.',
            ],
            'database_tables' => [
                'withheld' => 'Database tables',
                'count' => '7',
                'source' => 'CMP-DOC-11 §6.11',
                'note' => 'Integrity constraint 18 is the one that reaches this register: ratings is a '
                    .'withheld area, CC-025 records that CMP-DOC-11 §6.7 and CMP-DOC-10 §11.9 specify a '
                    .'table and an endpoint that must not be followed, and the constraint is marked WITHHELD '
                    .'rather than absent so that it never quietly becomes enforced.',
            ],
            'client_screens' => [
                'withheld' => 'Client screens',
                'count' => '14',
                'source' => 'CMP-DOC-12 §17',
                'note' => 'Mobile/ is empty. CMP-IMP-059 depends on documented Mobile requirements and '
                    .'CMP-IMP-010 is blocked on MOB-OQ-001.',
            ],
            'operator_capabilities' => [
                'withheld' => 'Operator capabilities',
                'count' => '5',
                'source' => 'CMP-DOC-17 §15',
                'note' => 'BAD-DEC-006 leaves the role set undecided and ADM-168 records that the '
                    .'administrative unit cannot start. Nine of the twelve CMP-DOC-17 §16 obligations are '
                    .'blocked in the obligation register for the same reason.',
            ],
            'use_cases_outlined' => [
                'withheld' => 'Use cases Outlined rather than Specified',
                'count' => '33',
                'source' => 'CMP-DOC-03',
                'note' => 'An Outlined use case has no decomposed behaviour, so FRD-RISK-002 — a developer '
                    .'implements a gap — is the risk CLAUDE.md §4 rule 2 names as the highest in the chain.',
            ],
            'functional_gaps' => [
                'withheld' => 'Functional gaps',
                'count' => '29, of which 11 Critical',
                'source' => 'CMP-DOC-04 §9.3',
                'note' => 'TC-182: the two Critical gaps on the money path — GAP-008 and GAP-009 — have no '
                    .'test because they have no behaviour. TC-183: GAP-013 fraud has no test, no requirement '
                    .'and no owner after seventeen documents. TC-184: GAP-017 pickup sequencing blocks '
                    .'FRD-FR-159 for multi-passenger trips.',
            ],
        ];
    }

    /**
     * The words that name a withheld capability, for `TC-178` ‡'s detector.
     *
     * Drawn from `CLAUDE.md` §5's prohibition list and CMP-DOC-04 §9.2's three
     * zero-requirement areas. A test **method** whose name contains one of these
     * is building a test for a withheld capability unless it is asserting the
     * platform's refusal or the thing's absence, which is `TC-179` ‡.
     *
     * @return list<string>
     */
    public static function withheldTerms(): array
    {
        return [
            'rating', 'wallet', 'reward', 'recurring_commute',
            'sos', 'refund', 'settlement', 'adjudication',
        ];
    }

    /**
     * The words that make a test about a withheld capability legitimate.
     *
     * `TC-179` ‡: *"where a journey reaches one, the test shall assert the
     * platform's refusal — the specified behaviour — and not an invented
     * outcome."* A test named for the absence of a thing, or for its refusal, is
     * the specified behaviour and is exactly what should exist.
     *
     * @return list<string>
     */
    public static function refusalTerms(): array
    {
        return ['no_', 'not_', 'never', 'refus', 'withheld', 'absent', 'cannot', 'must_not', 'forbid'];
    }
}
