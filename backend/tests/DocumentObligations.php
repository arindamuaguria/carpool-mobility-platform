<?php

declare(strict_types=1);

namespace Tests;

use Tests\Architecture\ObligationRegisterTest;
use Tests\Architecture\StructuralRulesTest;
use Tests\Integration\User\SessionEstablishmentTest;
use Tests\Integration\User\SessionLifecycleTest;

/**
 * The thirteen obligations placed **directly on CMP-DOC-18** — §17.2.
 *
 * Seven predecessors place an obligation on that document by name: six in their
 * *Obligations This Document Places on Others* section, and CMP-DOC-13 in a
 * section of its own. Ten plus three is thirteen.
 *
 * ## Why this is a third register and not a column in the first
 *
 * {@see ObligationRegister} carries the **99** — obligations on the *platform*,
 * each discharged by a test or a rule. These thirteen are obligations on a
 * *document*: several are discharged by CMP-DOC-18 saying something, and §17.2
 * records all thirteen as discharged **in that sense**. What it does not say, and
 * cannot, is whether the `TC-` statement it points at has an implementation.
 *
 * That is the gap this register closes. For each obligation it records the `TC-`
 * statement CMP-DOC-18 names, and then — separately — whether anything in this
 * repository implements it.
 *
 * ## Discharged by the document is not discharged by the platform
 *
 * §17.2 concludes *"all thirteen are discharged"*, and that is true of the
 * document. Six are discharged by the platform too. **Seven are not**, and each
 * names what stands in the way.
 *
 * An obligation is never marked enforced because a related test exists. Obligation
 * 1 is the clearest case: genuine parallelism is proven, on the idempotency
 * registry, by a test written for `TC-147` ‡ — and obligation 1 is about
 * **concurrent seat allocation**, which needs the `Ride` aggregate. The mechanism
 * being proven elsewhere is recorded in the note and changes the status not at all.
 *
 * ## The citations are checked against the document itself
 *
 * `DocumentObligationsTest` reads CMP-DOC-18 and fails the build if a `TC-`
 * statement cited here is not declared there. A register that cited a statement
 * nobody wrote would be a register asserting its own completeness.
 */
final class DocumentObligations
{
    /**
     * The thirteen, in §17.2's order.
     *
     * @return array<int, array{
     *     obligation: string,
     *     source: string,
     *     requirement: string,
     *     dischargedBy: list<string>,
     *     status: string,
     *     provenBy: ?string,
     *     note: string,
     * }>
     */
    public static function all(): array
    {
        return [
            1 => [
                'obligation' => 'Run the concurrency test under genuine parallelism.',
                'source' => 'CMP-DOC-09 §18.5',
                'requirement' => 'BE-208',
                'dischargedBy' => ['TC-143', 'TC-145'],
                'status' => ObligationRegister::BLOCKED,
                'provenBy' => null,
                'note' => 'TC-143 ‡ is concurrent **seat allocation**, and SeatAllocation belongs to the Ride '
                    .'aggregate (BE-018) which BAD-DEC-007 leaves unbuildable. The genuine-parallelism '
                    .'mechanism itself is proven — ConcurrentIdempotencyClaimTest races six operating-system '
                    .'processes and asserts the invariant across all outcomes, which is TC-145 ‡\'s discipline '
                    .'— but that is a different obligation (TC-147 ‡) and does not discharge this one.',
            ],
            2 => [
                'obligation' => 'Test all four failure treatments distinctly, not as one error path.',
                'source' => 'CMP-DOC-12 §18.7',
                'requirement' => 'UX-053',
                'dischargedBy' => ['TC-127', 'TC-128'],
                'status' => ObligationRegister::BLOCKED,
                'provenBy' => null,
                'note' => 'A failure **treatment** is a client presentation, and Mobile/ is empty — CMP-IMP-059 '
                    .'depends on documented Mobile requirements and CMP-IMP-010 is blocked on MOB-OQ-001. The '
                    .'interface half holds: ErrorBranchTest proves the four API branches are distinguishable '
                    .'by structure alone (API-072 ‡). TC-128 ‡ needs both halves — each branch producing its '
                    .'own treatment per the CMP-DOC-12 §7.6 mapping — so the API half alone does not discharge it.',
            ],
            3 => [
                'obligation' => 'Include an assistive-technology walkthrough.',
                'source' => 'CMP-DOC-12 §18.7',
                'requirement' => 'UX-201',
                'dischargedBy' => ['TC-161', 'TC-162'],
                'status' => ObligationRegister::BLOCKED,
                'provenBy' => null,
                'note' => 'TC-161 ‡ is a walkthrough against **every screen**, at level 6 (manual review). No '
                    .'screen exists, and MOB-OQ-002 leaves the accessibility standard undecided — the one '
                    .'blocked item of FEAT-037\'s sixteen. TC-162 makes it a specified procedure with a '
                    .'recorded outcome per screen, which cannot be specified against an undecided standard.',
            ],
            4 => [
                'obligation' => 'Carry the fourteen checks as test obligations.',
                'source' => 'CMP-DOC-13 §19.2',
                'requirement' => 'SEC-235',
                'dischargedBy' => ['TC-109', 'TC-110'],
                'status' => ObligationRegister::ENFORCED,
                'provenBy' => ObligationRegisterTest::class,
                'note' => 'Carrying them is the obligation, and all fourteen are carried as SEC-19.1/1 to '
                    .'SEC-19.1/14 with TC-110 ‡\'s non-suppressibility of checks 1 to 7 asserted as an exact '
                    .'set. Nine of the fourteen are themselves enforced and five are not; the register reports '
                    .'which, which is what carrying them is for.',
            ],
            5 => [
                'obligation' => 'Include negative authorisation cases for every operation, not a sample.',
                'source' => 'CMP-DOC-13 §19.2',
                'requirement' => 'SEC-236',
                'dischargedBy' => ['TC-116'],
                'status' => ObligationRegister::ENFORCED,
                'provenBy' => SessionLifecycleTest::class,
                'note' => 'Every operation in AuthorisationServiceProvider::sessionRules() has one, and '
                    .'DocumentObligationsTest asserts that rather than trusting it. Refresh had none when this '
                    .'register was written — terminate and establish did — and two of three is exactly the '
                    .'sample SEC-236 forbids. It was built in the same change.',
            ],
            6 => [
                'obligation' => 'Do not treat penetration testing as a substitute for any check.',
                'source' => 'CMP-DOC-13 §19.2',
                'requirement' => 'SEC-237',
                'dischargedBy' => ['TC-121', 'TC-122'],
                'status' => ObligationRegister::ENFORCED,
                'provenBy' => ObligationRegisterTest::class,
                'note' => 'Discharged by absence, and checked as such: no obligation in any register cites '
                    .'penetration testing as what proves it. TC-122 records that its scope and cadence are '
                    .'[TBD – Business Decision Required] (SEC-OQ-04) and that no claim of having been tested '
                    .'follows — so there is nothing that could be substituted even if somebody wanted to.',
            ],
            7 => [
                'obligation' => 'Carry the twelve payment obligations, with 1, 5 and 9 non-suppressible.',
                'source' => 'CMP-DOC-14 §17.7',
                'requirement' => 'PAY-207',
                'dischargedBy' => ['TC-001', 'TC-021'],
                'status' => ObligationRegister::ENFORCED,
                'provenBy' => ObligationRegisterTest::class,
                'note' => 'PAY-16/1 to PAY-16/12, with 1, 5 and 9 in the twenty-five asserted by reference. '
                    .'Eleven of the twelve are blocked on BAD-DEP-004 and T5; the twelfth is PAY-205\'s static '
                    .'rule, which holds.',
            ],
            8 => [
                'obligation' => 'Test concurrent verification of one payment under genuine parallelism.',
                'source' => 'CMP-DOC-14 §17.7',
                'requirement' => 'PAY-208',
                'dischargedBy' => ['TC-146'],
                'status' => ObligationRegister::BLOCKED,
                'provenBy' => null,
                'note' => 'BAD-DEP-004 leaves the PSP unselected and T5 monetary precision is unresolved, so '
                    .'DB-033 ‡ forbids every money column. There is no payment to verify concurrently. The '
                    .'parallelism mechanism exists (see obligation 1) and is not what is missing.',
            ],
            9 => [
                'obligation' => 'Carry the ten tracking obligations, with 1, 3 and 8 non-suppressible.',
                'source' => 'CMP-DOC-15 §16.7',
                'requirement' => 'GPS-184',
                'dischargedBy' => ['TC-001', 'TC-021'],
                'status' => ObligationRegister::ENFORCED,
                'provenBy' => ObligationRegisterTest::class,
                'note' => 'GPS-14/1 to GPS-14/10, with 1, 3 and 8 in the twenty-five. All ten are blocked — '
                    .'FEAT-019 is unbuilt and two of them are the client\'s — and carrying them is what makes '
                    .'that visible.',
            ],
            10 => [
                'obligation' => 'Exercise tracking obligation 1 across background and post-process-death states.',
                'source' => 'CMP-DOC-15 §16.7',
                'requirement' => 'GPS-181',
                'dischargedBy' => ['TC-136', 'TC-153'],
                'status' => ObligationRegister::BLOCKED,
                'provenBy' => null,
                'note' => 'Application state and process death are device concerns the backend suite cannot '
                    .'reach. Mobile/ is empty; CMP-IMP-010 is blocked on MOB-OQ-001 and no Android toolchain '
                    .'decision has been taken.',
            ],
            11 => [
                'obligation' => 'Carry the ten communication obligations, with 1, 2, 3 and 6 non-suppressible.',
                'source' => 'CMP-DOC-16 §15.7',
                'requirement' => 'NOTIF-188',
                'dischargedBy' => ['TC-001', 'TC-021'],
                'status' => ObligationRegister::ENFORCED,
                'provenBy' => ObligationRegisterTest::class,
                'note' => 'NOTIF-13/1 to NOTIF-13/10, with 1, 2, 3 and 6 in the twenty-five. Nine are blocked '
                    .'on FEAT-022 and FEAT-023; the first is NOTIF-187\'s static rule, which holds.',
            ],
            12 => [
                'obligation' => 'Carry the twelve administrative obligations, with 1 to 5 non-suppressible.',
                'source' => 'CMP-DOC-17 §17.7',
                'requirement' => 'ADM-203',
                'dischargedBy' => ['TC-001', 'TC-038'],
                'status' => ObligationRegister::ENFORCED,
                'provenBy' => StructuralRulesTest::class,
                'note' => 'ADM-16/1 to ADM-16/12, with 1 to 5 in the twenty-five. Obligations 1 and 2 are '
                    .'TC-037 ‡ rules 5 and 10, which TC-038 ‡ makes non-suppressible and StructuralRulesTest '
                    .'enforces — ADM-204 ‡ names them as what makes SRS-RISK-003 structural rather than '
                    .'aspirational. The other ten are blocked on BAD-DEC-006.',
            ],
            13 => [
                'obligation' => 'Exercise administrative obligation 5 against the most privileged role in existence.',
                'source' => 'CMP-DOC-17 §17.7',
                'requirement' => 'ADM-200',
                'dischargedBy' => ['TC-080'],
                'status' => ObligationRegister::BLOCKED,
                'provenBy' => null,
                'note' => 'TC-080 ‡ tests an operator action breaching an absolute rule as refused and '
                    .'recorded, and there is no operator action to test: BAD-DEC-006 leaves the role set '
                    .'undecided (SEC-063) and ADM-168 records the administrative unit as unable to start. '
                    .'RegisteredActorRolesTest does assert that an actor holding no role is refused an '
                    .'administrative capability, which is the empty case of "the most privileged role that '
                    .'exists at the time of the test" — but the interventions ADM-200 requires it to be '
                    .'exercised against do not exist, so this is not discharged.',
            ],
        ];
    }

    /**
     * Operation name to the negative authorisation case that proves it refuses.
     *
     * `TC-116` ‡ / `SEC-236`: *"negative authorisation cases shall exist for
     * **every** operation, not a sample."* Declared as a map so that
     * `DocumentObligationsTest` can check it against the platform's own
     * authorisation policy — an operation added without a negative case fails the
     * build, which is the only way "every" stays true.
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function negativeAuthorisationCases(): array
    {
        return [
            'sessions.establish' => [
                SessionEstablishmentTest::class,
                'test_a_caller_cannot_establish_a_session_for_somebody_else',
            ],
            'sessions.current.terminate' => [
                SessionLifecycleTest::class,
                'test_another_actor_cannot_terminate_a_session_they_do_not_hold',
            ],
            'sessions.current.refresh' => [
                SessionLifecycleTest::class,
                'test_another_actor_cannot_refresh_a_session_they_do_not_hold',
            ],
        ];
    }

    /**
     * Where the authoritative document lives, relative to this package.
     *
     * The register is checked **against CMP-DOC-18 itself** rather than against a
     * copy of it, so a `TC-` statement cited here and absent there fails the
     * build. `CLAUDE.md` §3 makes the `.md` the authority and the `.docx` a
     * generated presentation, so this reads the `.md`.
     */
    public static function documentPath(): string
    {
        return dirname(__DIR__, 2).'/Document/18_Testing_QA/DOC-18-TESTING-CMP-Testing-QA.md';
    }
}
