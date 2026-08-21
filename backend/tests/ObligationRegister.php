<?php

declare(strict_types=1);

namespace Tests;

use Tests\Architecture\StructuralRulesTest;
use Tests\Contract\ErrorBranchTest;
use Tests\Contract\RequestSchemaTest;
use Tests\Contract\SessionOperationsTest;
use Tests\Domain\Authorisation\AuthoriserTest;
use Tests\Domain\StateMachine\StateMachineTest;
use Tests\Domain\User\Argon2idAuthenticationMaterialTest;
use Tests\Integration\Evidence\EvidentialLogTest;
use Tests\Integration\Persistence\DatabaseAccountGrantsTest;
use Tests\Integration\Persistence\IdempotencyRegistryTest;
use Tests\Integration\Persistence\IntegrityConstraintsHoldTest;
use Tests\Integration\Persistence\PaymentCredentialAbsenceTest;

/**
 * The consolidated verification obligation register — CMP-DOC-18 §4.
 *
 * `TC-001`: *"The 99 obligations above shall be consolidated into one register."*
 * `TC-005`: *"The register shall be the authority; a predecessor's list is a
 * reference into it."* This is that register, and `TADR-01` is why it is one file
 * rather than nine — nine authors each protected what they could see, and
 * `TC-014` records that nobody could see the whole.
 *
 * ## The seven fields `TC-007` requires, and two more
 *
 * `TC-002` keeps each obligation's source document and section; `TC-003` gives it
 * exactly one owning level; `TC-004` records whether it is suppressible; `TC-008`
 * requires the verified-statements field to name **statement identifiers, not
 * prose**, and `TC-010` makes an obligation with no named verified statement a
 * defect in the register.
 *
 * Two fields are added here and reported as this file's own:
 *
 * - **`status`** — `TC-009` says status is maintained as implementation proceeds.
 *   §4.2 offers *Specified / Implemented / Passing*, which cannot express the
 *   distinction this platform actually needs: an obligation nobody can build yet
 *   is not in the same position as one nobody has built yet, and neither is in
 *   the same position as one that must **never** be built. Five states are used
 *   instead — see the constants — and each non-enforced one carries a note naming
 *   what stands in the way.
 * - **`provenBy`** — the test class that proves it. `TC-009` leaves status to be
 *   *"maintained"*, and a status maintained by hand is a status that drifts.
 *   `ObligationRegisterTest` requires every `ENFORCED` entry to name a class that
 *   **exists**, so a claim of enforcement cannot outlive the test that made it
 *   true.
 *
 * ## What this register does not do
 *
 * It reports **no coverage figure**. `TC-020` is explicit: *"reporting a coverage
 * figure before `TC-017` is complete would overstate what is verified, and no such
 * figure appears in this document."* `TC-017`'s statement-by-statement mapping of
 * 1,164 integrity-critical statements has not been done, and this register does
 * not pretend otherwise — it says which of the 99 hold, and nothing about the
 * 1,164.
 *
 * `TC-006`: an obligation present in a predecessor and absent from here is a
 * **documentation defect**, which is why `ObligationRegisterTest` asserts the
 * per-source counts against CMP-DOC-18 Appendix C rather than merely totalling
 * whatever this file happens to hold.
 *
 * @phpstan-type Obligation array{
 *     source: string,
 *     section: string,
 *     obligation: string,
 *     verifies: list<string>,
 *     level: int,
 *     suppressible: bool,
 *     technique: string,
 *     status: string,
 *     provenBy: ?string,
 *     note: string,
 * }
 */
final class ObligationRegister
{
    /** A named test or static rule proves it today. */
    public const ENFORCED = 'enforced';

    /**
     * Buildable, and not built.
     *
     * The thing it verifies does not exist yet for ordinary sequencing reasons —
     * no decision is missing and nothing forbids it. An `ABSENT` entry is work,
     * not a blocker.
     */
    public const ABSENT = 'absent';

    /**
     * Cannot be built: an authoritative prerequisite is unresolved.
     *
     * A business decision nobody has taken, or a supplier nobody has selected.
     * The note names it. `TADR-12`: *"unverifiable is recorded, never padded."*
     */
    public const BLOCKED = 'blocked';

    /**
     * Must never be built.
     *
     * `TADR-13`: *"no test for a withheld capability."* `ADM-187`/`ADM-191` forbid
     * a withheld item being stubbed, prototyped, disabled or flagged, and a test
     * for one would be the same mistake in a different file.
     */
    public const WITHHELD = 'withheld';

    /**
     * The obligation lands somewhere this repository is not.
     *
     * Recorded rather than dropped, so that the count still reconciles with
     * CMP-DOC-18 Appendix C and `TC-006` holds.
     */
    public const NOT_APPLICABLE = 'not_applicable';

    /**
     * The six levels of `TC-025`. One owning level each (`TC-003`, `TADR-02`).
     */
    public const LEVEL_STATIC = 1;

    public const LEVEL_DOMAIN = 2;

    public const LEVEL_INTEGRATION = 3;

    public const LEVEL_CONTRACT = 4;

    public const LEVEL_SYSTEM = 5;

    public const LEVEL_REVIEW = 6;

    /**
     * The techniques §4.2 names.
     *
     * @var list<string>
     */
    public const TECHNIQUES = [
        'static', 'assertion', 'induced failure', 'parallel execution', 'inspection', 'walkthrough',
    ];

    /**
     * Every obligation, keyed by `TC-007`'s `<source>-<n>` reference.
     *
     * @return array<string, Obligation>
     */
    public static function all(): array
    {
        return array_merge(
            self::backendTestObligations(),
            self::backendStructuralRules(),
            self::interfaceObligations(),
            self::databaseConstraints(),
            self::securityChecks(),
            self::paymentObligations(),
            self::positionObligations(),
            self::notificationObligations(),
            self::administrativeObligations(),
        );
    }

    /**
     * CMP-DOC-09 §17.1 — nine, none non-suppressible.
     *
     * @return array<string, Obligation>
     */
    private static function backendTestObligations(): array
    {
        return array_merge(
            self::backendObligation(
                n: 1,
                obligation: 'Every domain invariant shall have a test asserting refusal of its violation.',
                verifies: ['BE-207'],
                level: self::LEVEL_DOMAIN,
                technique: 'assertion',
                status: self::ENFORCED,
                provenBy: StateMachineTest::class,
                note: 'Holds for the invariants that exist — the state machine, Session, User and '
                    .'ConcurrentSessionLimit. BE-023 to BE-030 belong to aggregates that are unbuilt, and '
                    .'this obligation grows with each one. DB-208 states the same discipline for constraints.',
            ),
            self::backendObligation(
                n: 2,
                obligation: 'Concurrent allocation shall be tested under genuine parallel execution, not simulated sequence.',
                verifies: ['BE-208', 'BADR-04', 'SRS-REQ-039'],
                level: self::LEVEL_INTEGRATION,
                technique: 'parallel execution',
                status: self::BLOCKED,
                note: 'SeatAllocation belongs to the Ride aggregate (BE-018), and BAD-DEC-007 leaves the '
                    .'booking model open. There is nothing to contend over.',
            ),
            self::backendObligation(
                n: 3,
                obligation: 'Atomicity of the booking transaction shall be tested by induced mid-transaction failure.',
                verifies: ['BE-209', 'BE-048', 'BE-053'],
                level: self::LEVEL_INTEGRATION,
                technique: 'induced failure',
                status: self::BLOCKED,
                note: 'No booking transaction exists; BAD-DEC-007 is open.',
            ),
            self::backendObligation(
                n: 4,
                obligation: 'Every port result — including Unavailable — shall be exercised through its test adapter.',
                verifies: ['BE-210', 'BE-163', 'BE-152'],
                level: self::LEVEL_INTEGRATION,
                technique: 'assertion',
                status: self::ABSENT,
                note: 'CapabilityResultTest exercises all four results, but not through an adapter: none of '
                    .'CMP-DOC-09 §12\'s five ports declares an operation, so there is no adapter to substitute. '
                    .'PortServiceProvider::ports() names what blocks each.',
            ),
            self::backendObligation(
                n: 5,
                obligation: 'Idempotent replay shall be tested for every state-changing operation.',
                verifies: ['BE-211', 'BE-135', 'BADR-08'],
                level: self::LEVEL_INTEGRATION,
                technique: 'assertion',
                status: self::ENFORCED,
                provenBy: IdempotencyRegistryTest::class,
                note: 'The concurrent dimension is CMP-DOC-18\'s own TC-147 ‡ rather than one of the 99. '
                    .'ConcurrentIdempotencyClaimTest proves it under genuine parallelism (TADR-08) — six '
                    .'operating-system processes released at one instant, exactly one of which takes the claim.',
            ),
            self::backendObligation(
                n: 6,
                obligation: 'Evidential chain verification shall be tested against a deliberately altered record.',
                verifies: ['BE-212', 'BE-109', 'BE-115'],
                level: self::LEVEL_INTEGRATION,
                technique: 'induced failure',
                status: self::ENFORCED,
                provenBy: EvidentialLogTest::class,
                note: '',
            ),
            self::backendObligation(
                n: 7,
                obligation: 'Projection rebuild shall be tested for equivalence with incrementally maintained state.',
                verifies: ['BE-213', 'BE-120'],
                level: self::LEVEL_INTEGRATION,
                technique: 'assertion',
                status: self::ABSENT,
                note: 'No projection exists. ARCH-113 places them with BE-017\'s aggregates.',
            ),
            self::backendObligation(
                n: 8,
                obligation: 'Administrative operations shall be tested for refusal where they would breach an absolute rule.',
                verifies: ['BE-214', 'BE-079'],
                level: self::LEVEL_INTEGRATION,
                technique: 'assertion',
                status: self::BLOCKED,
                note: 'BAD-DEC-006 leaves the role set undecided; ADM-168 records that the administrative '
                    .'unit cannot start without it.',
            ),
            self::backendObligation(
                n: 9,
                obligation: 'Safety operations shall be tested with non-essential dependencies made unavailable.',
                verifies: ['BE-215', 'BE-194', 'BE-197'],
                level: self::LEVEL_INTEGRATION,
                technique: 'induced failure',
                status: self::BLOCKED,
                note: 'BAD-DEC-011 is open, no response capability is staffed (BAD-RISK-005), and '
                    .'EmergencyDispatchPort is withheld — PortServiceProvider::withheldPorts().',
            ),
        );
    }

    /**
     * CMP-DOC-09 §17.2 — eight structural rules; 5, 6 and 8 non-suppressible
     * (`BE-218`).
     *
     * All eight are the first eight of `TC-037` ‡'s thirteen, which
     * `StructuralRulesTest::RULES` declares and which fail the build.
     *
     * @return array<string, Obligation>
     */
    private static function backendStructuralRules(): array
    {
        $rules = [
            1 => ['Domain references no framework type', 'BE-002', true],
            2 => ['Dependencies point inward only', 'BE-003', true],
            3 => ['No ORM type outside the three permitted namespaces', 'BE-087', true],
            4 => ['No transaction control outside Application', 'BE-047', true],
            5 => ['No ORM access from the administrative namespace', 'BE-075', false],
            6 => ['No evidential write outside the writer', 'BE-105', false],
            7 => ['No provider type above an adapter', 'BE-153', true],
            8 => ['No business rule in a controller, resource, model, listener or job', 'BE-011', false],
        ];

        $register = [];

        foreach ($rules as $n => [$rule, $protects, $suppressible]) {
            $register['BE-17.2/'.$n] = [
                'source' => 'CMP-DOC-09',
                'section' => '§17.2',
                'obligation' => $rule.'.',
                'verifies' => [$protects, 'BE-216', 'BE-217'],
                'level' => self::LEVEL_STATIC,
                'suppressible' => $suppressible,
                'technique' => 'static',
                'status' => self::ENFORCED,
                'provenBy' => StructuralRulesTest::class,
                'note' => '',
            ];
        }

        return $register;
    }

    /**
     * CMP-DOC-10 §16 — three obligations requiring a test.
     *
     * §16 states ten items; three of them place a **test** obligation and are the
     * three CMP-DOC-18 Appendix C counts. The other seven are interface qualities
     * verified elsewhere or, in `API-216`'s case, a `[TBD]` recording that no
     * performance figure is stated.
     *
     * @return array<string, Obligation>
     */
    private static function interfaceObligations(): array
    {
        return [
            'API-16/1' => [
                'source' => 'CMP-DOC-10', 'section' => '§16',
                'obligation' => 'Every integrity-critical statement in this document shall have an automated contract test.',
                'verifies' => ['API-213', 'NFR-106', 'BE-207'],
                'level' => self::LEVEL_CONTRACT,
                'suppressible' => true,
                'technique' => 'assertion',
                'status' => self::ABSENT,
                'provenBy' => null,
                'note' => 'CMP-DOC-10 holds 100 ‡ statements. The contract suite covers the operations that '
                    .'exist — versioning, media type, idempotency, the four error branches, the request schema, '
                    .'sessions, health and configuration. The remainder describe operations CMP-DOC-10 §11 '
                    .'catalogues and BE-017\'s aggregates have not made buildable. TC-017 is the mapping that '
                    .'would say exactly which, and TC-020 forbids reporting a figure before it is done.',
            ],
            'API-16/2' => [
                'source' => 'CMP-DOC-10', 'section' => '§16',
                'obligation' => 'A contract test shall exist asserting that no request schema accepts an authoritative field.',
                'verifies' => ['API-214', 'API-037', 'AADR-06'],
                'level' => self::LEVEL_CONTRACT,
                'suppressible' => true,
                'technique' => 'assertion',
                'status' => self::ENFORCED,
                'provenBy' => RequestSchemaTest::class,
                'note' => 'Also enforced statically as TC-037 ‡ rule 11, which is non-suppressible under TC-038 ‡.',
            ],
            'API-16/3' => [
                'source' => 'CMP-DOC-10', 'section' => '§16',
                'obligation' => 'A contract test shall exist for each of the four error branches, asserting that a condition of one branch never returns as another.',
                'verifies' => ['API-215', 'API-073', 'API-074', 'API-075', 'API-076'],
                'level' => self::LEVEL_CONTRACT,
                'suppressible' => true,
                'technique' => 'assertion',
                'status' => self::ENFORCED,
                'provenBy' => ErrorBranchTest::class,
                'note' => '',
            ],
        ];
    }

    /**
     * CMP-DOC-11 §15 — the twenty-one integrity constraints.
     *
     * The register of record for these is {@see IntegrityConstraints}, which
     * `DB-212` requires to be *"reviewable as a single list"* and which
     * `CMP-IMP-051` extended to twenty-three under `DB-208`. This reads its status
     * rather than restating it, so the two cannot disagree — a second copy of a
     * status is a second thing to forget to update.
     *
     * @return array<string, Obligation>
     */
    private static function databaseConstraints(): array
    {
        $register = [];

        foreach (IntegrityConstraints::all() as $n => $constraint) {
            if ($n > 21) {
                // 22 and 23 arrived with CMP-IMP-051 under DB-208. CMP-DOC-18
                // Appendix C counts twenty-one, and TC-006 makes a mismatch a
                // documentation defect — so they are enforced and tested, and
                // they are not counted into the 99.
                continue;
            }

            $status = match ($constraint['status']) {
                IntegrityConstraints::ENFORCED => self::ENFORCED,
                IntegrityConstraints::WITHHELD => self::WITHHELD,
                default => self::ABSENT,
            };

            $register['DB-15/'.$n] = [
                'source' => 'CMP-DOC-11',
                'section' => '§15',
                'obligation' => $constraint['constraint'].'.',
                'verifies' => array_map(trim(...), explode(',', $constraint['protects'])),
                // DB-205 ‡: the three grants are asserted the same way as the
                // schema objects — by attempting the forbidden operation — so
                // they own the same level.
                'level' => self::LEVEL_INTEGRATION,
                'suppressible' => true,
                'technique' => 'induced failure',
                'status' => $status,
                'provenBy' => $status === self::ENFORCED
                    ? IntegrityConstraintsHoldTest::class
                    : null,
                'note' => $constraint['note'],
            ];
        }

        return $register;
    }

    /**
     * CMP-DOC-13 §19.1 — fourteen automated checks; **1 to 7 are
     * non-suppressible** (`SEC-232` ‡).
     *
     * @return array<string, Obligation>
     */
    private static function securityChecks(): array
    {
        return array_merge(
            self::securityCheck(
                n: 1,
                obligation: 'Attempt an UPDATE on an evidential record as the application account; require server refusal.',
                verifies: ['SEC-110', 'DB-118'],
                level: self::LEVEL_INTEGRATION,
                technique: 'induced failure',
                status: self::ENFORCED,
                provenBy: DatabaseAccountGrantsTest::class,
            ),
            self::securityCheck(
                n: 2,
                obligation: 'Attempt a DELETE on a ledger entry as the application account; require server refusal.',
                verifies: ['DB-094'],
                level: self::LEVEL_INTEGRATION,
                technique: 'induced failure',
                status: self::BLOCKED,
                note: 'No led_ table exists. T5 monetary precision (DB-032, GAP-016) is unresolved and '
                    .'DB-033 ‡ forbids a money column until it is, so the ledger cannot be created. The grant '
                    .'itself is already withheld — DatabaseAccountGrantsTest asserts the application account '
                    .'holds no DELETE on led_ — but there is no entry to attempt the delete on.',
            ),
            self::securityCheck(
                n: 3,
                obligation: 'Attempt DDL as the application account; require server refusal.',
                verifies: ['DB-119'],
                level: self::LEVEL_INTEGRATION,
                technique: 'induced failure',
                status: self::ENFORCED,
                provenBy: DatabaseAccountGrantsTest::class,
            ),
            self::securityCheck(
                n: 4,
                obligation: 'Alter a record directly and run chain verification; require divergence to be reported at that record.',
                verifies: ['SEC-111'],
                level: self::LEVEL_INTEGRATION,
                technique: 'induced failure',
                status: self::ENFORCED,
                provenBy: EvidentialLogTest::class,
            ),
            self::securityCheck(
                n: 5,
                obligation: 'Static analysis: no dynamic statement construction anywhere.',
                verifies: ['SEC-131'],
                level: self::LEVEL_STATIC,
                technique: 'static',
                status: self::ENFORCED,
                provenBy: StructuralRulesTest::class,
                note: 'TC-037 ‡ rule 9, non-suppressible under TC-038 ‡.',
            ),
            self::securityCheck(
                n: 6,
                obligation: 'Schema inspection: no request schema accepts an authoritative field.',
                verifies: ['API-214', 'SEC-134'],
                level: self::LEVEL_STATIC,
                technique: 'inspection',
                status: self::ENFORCED,
                provenBy: StructuralRulesTest::class,
                note: 'TC-037 ‡ rule 11, non-suppressible under TC-038 ‡; also a contract test (API-16/2).',
            ),
            self::securityCheck(
                n: 7,
                obligation: 'Schema inspection: no column exists for a payment instrument credential.',
                verifies: ['SEC-135', 'SADR-10', 'DB-037'],
                level: self::LEVEL_INTEGRATION,
                technique: 'inspection',
                status: self::ENFORCED,
                provenBy: PaymentCredentialAbsenceTest::class,
                note: 'The register surfaced this as absent when it was first written, and it was built in '
                    .'the same change: nothing blocked it, and SEC-232 ‡ makes it non-suppressible.',
            ),
            self::securityCheck(
                n: 8,
                obligation: 'Log inspection under exercise: no credential, token, position or contact detail present.',
                verifies: ['SEC-208', 'SEC-038', 'BE-201'],
                level: self::LEVEL_SYSTEM,
                technique: 'inspection',
                status: self::ABSENT,
                note: 'Partly covered where it matters most: SessionLifecycleTest, SessionEstablishmentTest '
                    .'and SessionEndpointTest each assert that no token, hash or phone number reaches an '
                    .'evidential record. What is absent is an inspection of the **operational** log under '
                    .'exercise, which BE-202 keeps distinct from the evidential one. Buildable; nothing blocks it.',
            ),
            self::securityCheck(
                n: 9,
                obligation: 'Authorisation: every operation refuses an unentitled caller, and absence is indistinguishable from non-entitlement.',
                verifies: ['SEC-055', 'SEC-069'],
                level: self::LEVEL_INTEGRATION,
                technique: 'assertion',
                status: self::ENFORCED,
                provenBy: AuthoriserTest::class,
                note: 'SEC-055 ‡\'s deny-by-default is asserted for an operation with no stated rule, and '
                    .'AuthorisationRefusal carries exactly one case so SEC-069 ‡ holds by construction.',
            ),
            self::securityCheck(
                n: 10,
                obligation: 'Operator path: an operator action breaching an absolute rule is refused and recorded.',
                verifies: ['SEC-011'],
                level: self::LEVEL_INTEGRATION,
                technique: 'assertion',
                status: self::BLOCKED,
                note: 'BAD-DEC-006 leaves the role set undecided (SEC-063), so no operator exists to act. '
                    .'ADM-168 records the administrative unit as unable to start.',
            ),
            self::securityCheck(
                n: 11,
                obligation: 'Session: a terminated token is refused identically to an unknown one.',
                verifies: ['SEC-048', 'API-103'],
                level: self::LEVEL_CONTRACT,
                technique: 'assertion',
                status: self::ENFORCED,
                provenBy: SessionOperationsTest::class,
                note: 'Four shapes of unusable token produce byte-identical bodies; SessionEndpointTest '
                    .'proves the same end to end for a genuinely terminated one.',
            ),
            self::securityCheck(
                n: 12,
                obligation: 'Storage: no store returns a value presentable back to the platform as a credential.',
                verifies: ['SEC-033', 'NFR-053'],
                level: self::LEVEL_DOMAIN,
                technique: 'assertion',
                status: self::ENFORCED,
                provenBy: Argon2idAuthenticationMaterialTest::class,
                note: 'The stored value does not match itself, which is the obvious attempt.',
            ),
            self::securityCheck(
                n: 13,
                obligation: 'Transport: the platform refuses unprotected transport, in every environment.',
                verifies: ['SEC-092', 'NFR-062'],
                level: self::LEVEL_SYSTEM,
                technique: 'assertion',
                status: self::BLOCKED,
                note: 'BAD-DEP-009 has selected no hosting and CMP-DOC-19 names no provider, so there is no '
                    .'deployed transport to refuse anything. OPS-098 places the mechanism at deployment.',
            ),
            self::securityCheck(
                n: 14,
                obligation: 'Restore: after a restore and reconciliation, removed data is absent and the chain verifies.',
                verifies: ['SEC-223', 'SEC-228'],
                level: self::LEVEL_SYSTEM,
                technique: 'induced failure',
                status: self::BLOCKED,
                note: 'BAD-DEC-021 leaves retention undecided — eight of nine periods unset — so what is '
                    .'removed on a restore is undefined, and BAD-DEP-009 leaves no environment to restore into.',
            ),
        );
    }

    /**
     * CMP-DOC-14 §16 — twelve; 1, 5 and 9 non-suppressible (`PAY-204` ‡).
     *
     * @return array<string, Obligation>
     */
    private static function paymentObligations(): array
    {
        $blocked = 'BAD-DEP-004 leaves the PSP unselected and T5 monetary precision (DB-032, GAP-016) is '
            .'unresolved, so DB-033 ‡ forbids every money column. No payment aggregate, table or operation exists.';

        $obligations = [
            1 => ['Verification runs for an attempt where the client never returned.', ['PAY-054', 'FRD-FR-125']],
            2 => ['The UPI application\'s response is absent from every log, store and code path.', ['PAY-038', 'PAY-039']],
            3 => ['A Reported result leaves the payment pending.', ['PAY-102']],
            4 => ['An Unavailable result leaves the payment pending.', ['PAY-103']],
            5 => ['Pending does not change state on elapsed time, however long.', ['PAY-079']],
            6 => ['No path sets payment status to a fourth value; the constraint refuses it.', ['PAY-078', 'DB-065']],
            7 => ['A response with a mismatched amount fails plausibility and returns Unavailable.', ['PAY-105', 'PAY-108']],
            8 => ['A replayed callback produces one verification, not two.', ['PAY-118', 'PAY-125']],
            9 => ['A callback cannot set a status by any route.', ['PAY-116']],
            10 => ['The confirmation transaction rolls back entirely on induced mid-transaction failure.', ['PAY-070', 'PAY-176']],
            11 => ['Ledger entries for a verified payment sum to zero.', ['PAY-148']],
            12 => ['An operator cannot set verified without recording a basis.', ['PAY-135', 'PAY-085']],
        ];

        $register = [];

        foreach ($obligations as $n => [$obligation, $verifies]) {
            // PAY-205: obligation 2 "shall be a static analysis rule as well as a
            // test", and the static half is TC-037 ‡ rule 13 — which holds today
            // and is non-suppressible under TC-038 ‡. The test half waits with
            // the rest of the area.
            $isStaticRule = $n === 2;

            $register['PAY-16/'.$n] = [
                'source' => 'CMP-DOC-14',
                'section' => '§16',
                'obligation' => $obligation,
                'verifies' => $verifies,
                'level' => $isStaticRule ? self::LEVEL_STATIC : self::LEVEL_INTEGRATION,
                // PAY-204 ‡, verbatim: "Obligations 1, 5 and 9 shall be
                // non-suppressible; each guards a zero-tolerance requirement."
                'suppressible' => ! in_array($n, [1, 5, 9], true),
                'technique' => $isStaticRule ? 'static' : 'assertion',
                'status' => $isStaticRule ? self::ENFORCED : self::BLOCKED,
                'provenBy' => $isStaticRule ? StructuralRulesTest::class : null,
                'note' => $isStaticRule
                    ? 'The static half holds — TC-037 ‡ rule 13, non-suppressible under TC-038 ‡. PAY-205 also '
                        .'requires a test, which waits with the rest of the payment area.'
                    : $blocked,
            ];
        }

        return $register;
    }

    /**
     * CMP-DOC-15 §14 — ten; 1, 3 and 8 non-suppressible (`GPS-180` ‡).
     *
     * @return array<string, Obligation>
     */
    private static function positionObligations(): array
    {
        $blocked = 'FEAT-019 is unbuilt. BAD-DEC-021 (retention) and FRD-OQ-009 are open, no trip aggregate '
            .'exists, and the mapping provider has no adapter (BE-161, MappingPort unbound).';

        $client = 'Acquisition is the client\'s. Mobile/ is empty — no Android toolchain decision has been '
            .'taken and CMP-IMP-010 is blocked on the supported device range (MOB-OQ-001). GPS-181 requires '
            .'this one to be exercised across application states including after process death, which is a '
            .'device concern the backend suite cannot reach.';

        $obligations = [
            1 => ['No location is acquired when no trip is active, in any application state.', ['GPS-021', 'GPS-022'], $client],
            2 => ['Acquisition stops and location is released when the trip ends.', ['GPS-015', 'GPS-016'], $client],
            3 => ['A position older than the configured bound is never presented as current.', ['GPS-062', 'GPS-064'], $blocked],
            4 => ['Changing the configured bound changes presentation without a client release.', ['GPS-059', 'GPS-060'], $blocked],
            5 => ['No position is extrapolated between reports.', ['GPS-068'], $blocked],
            6 => ['An estimate whose inputs are stale is reported unavailable, not carried forward.', ['GPS-074', 'GPS-075'], $blocked],
            7 => ['A trip starts, runs and completes with no position at all.', ['GPS-006', 'GPS-011'], $blocked],
            8 => ['One passenger\'s cancellation leaves every other booking on the trip valid.', ['GPS-090'], $blocked],
            9 => ['An implausible mapping response returns Unavailable, never a result.', ['GPS-137'], $blocked],
            10 => ['Removing position history leaves the completed trip\'s captured route intact.', ['GPS-120'], $blocked],
        ];

        $register = [];

        foreach ($obligations as $n => [$obligation, $verifies, $note]) {
            $register['GPS-14/'.$n] = [
                'source' => 'CMP-DOC-15',
                'section' => '§14',
                'obligation' => $obligation,
                'verifies' => $verifies,
                'level' => self::LEVEL_INTEGRATION,
                // GPS-180 ‡, verbatim: "Obligations 1, 3 and 8 shall be
                // non-suppressible; each guards an integrity-critical statement."
                'suppressible' => ! in_array($n, [1, 3, 8], true),
                'technique' => 'assertion',
                'status' => self::BLOCKED,
                'provenBy' => null,
                'note' => $note,
            ];
        }

        return $register;
    }

    /**
     * CMP-DOC-16 §13 — ten; 1, 2, 3 and 6 non-suppressible (`NOTIF-186` ‡).
     *
     * @return array<string, Obligation>
     */
    private static function notificationObligations(): array
    {
        $blocked = 'FEAT-022 and FEAT-023 are unbuilt. NotificationPort declares no operation — CMP-IMP-334 '
            .'adds them — and no notification category, preference surface or conversation exists.';

        $obligations = [
            1 => ['A notification payload contains no fare, amount, status, standing or position.', ['NOTIF-133', 'NOTIF-134']],
            2 => ['A safety-category notification is delivered to a user who has disabled everything disableable.', ['NOTIF-084', 'NOTIF-088']],
            3 => ['The preference surface offers no control for safety or payment.', ['NOTIF-086']],
            4 => ['A failed delivery leaves the notification in history.', ['NOTIF-102', 'NOTIF-105']],
            5 => ['A re-handled event produces one notification, not two.', ['NOTIF-067', 'NOTIF-068']],
            6 => ['A message is not delivered to a party with no qualifying relationship.', ['NOTIF-005']],
            7 => ['A cancelled relationship makes the conversation read-only and leaves history intact.', ['NOTIF-009', 'NOTIF-055']],
            8 => ['An offline message is never shown as delivered before acceptance.', ['NOTIF-035']],
            9 => ['No read state or proxy for it is exposed to a sender.', ['NOTIF-029', 'NOTIF-031']],
            10 => ['A rolled-back operation issues no notification.', ['NOTIF-072']],
        ];

        $register = [];

        foreach ($obligations as $n => [$obligation, $verifies]) {
            // NOTIF-187: obligation 1 "shall be a static analysis rule as well as
            // a test", and the static half is TC-037 ‡ rule 12.
            $isStaticRule = $n === 1;

            $register['NOTIF-13/'.$n] = [
                'source' => 'CMP-DOC-16',
                'section' => '§13',
                'obligation' => $obligation,
                'verifies' => $verifies,
                'level' => $isStaticRule ? self::LEVEL_STATIC : self::LEVEL_INTEGRATION,
                // NOTIF-186 ‡, verbatim: "Obligations 1, 2, 3 and 6 shall be
                // non-suppressible."
                'suppressible' => ! in_array($n, [1, 2, 3, 6], true),
                'technique' => $isStaticRule ? 'static' : 'assertion',
                'status' => $isStaticRule ? self::ENFORCED : self::BLOCKED,
                'provenBy' => $isStaticRule ? StructuralRulesTest::class : null,
                'note' => $isStaticRule
                    ? 'The static half holds — TC-037 ‡ rule 12, non-suppressible under TC-038 ‡. NOTIF-187 '
                        .'also requires a test, which waits with the notification area.'
                    : $blocked,
            ];
        }

        return $register;
    }

    /**
     * CMP-DOC-17 §16 — twelve; 1 to 5 non-suppressible (`ADM-198` ‡).
     *
     * @return array<string, Obligation>
     */
    private static function administrativeObligations(): array
    {
        $blocked = 'BAD-DEC-006 leaves the role set undecided (SEC-063) and ADM-168 records that the '
            .'administrative unit cannot start without it. No Filament resource, projection or operator '
            .'surface exists.';

        $obligations = [
            1 => ['Static analysis: no ORM type in the administrative namespace.', ['ADM-005', 'ADM-006'], 5],
            2 => ['Static analysis: no Filament resource declares a model.', ['ADM-002'], 10],
            3 => ['An intervention that would oversell a seat is refused, and the refusal recorded.', ['ADM-074', 'ADM-079'], null],
            4 => ['An intervention setting payment status outside verification is refused, and recorded.', ['ADM-076', 'ADM-079'], null],
            5 => ['No role, however privileged, can complete either.', ['ADM-161', 'ADM-162'], null],
            6 => ['Inspecting a record writes nothing to business state.', ['ADM-063', 'ADM-064'], null],
            7 => ['Inspecting a record writes exactly one evidential record.', ['ADM-065'], null],
            8 => ['Message and position access each write their own evidential record.', ['ADM-178', 'ADM-179'], null],
            9 => ['A safety incident cannot be closed without an outcome, by any path including bulk.', ['ADM-113', 'ADM-114'], null],
            10 => ['A support case cannot be closed without an outcome; unresolved is accepted.', ['ADM-131', 'ADM-132'], null],
            11 => ['A payment determination without a recorded basis is refused.', ['ADM-098'], null],
            12 => ['An unimplemented measure reports unavailable and is distinguishable from zero.', ['ADM-139', 'ADM-140'], null],
        ];

        $register = [];

        foreach ($obligations as $n => [$obligation, $verifies, $staticRule]) {
            $register['ADM-16/'.$n] = [
                'source' => 'CMP-DOC-17',
                'section' => '§16',
                'obligation' => $obligation,
                'verifies' => $verifies,
                'level' => $staticRule === null ? self::LEVEL_INTEGRATION : self::LEVEL_STATIC,
                // ADM-198 ‡, verbatim: "Obligations 1, 2, 3, 4 and 5 shall be
                // non-suppressible." ADM-204 ‡ adds that 1 and 2 are what make
                // SRS-RISK-003 structural rather than aspirational.
                'suppressible' => ! in_array($n, [1, 2, 3, 4, 5], true),
                'technique' => $staticRule === null ? 'assertion' : 'static',
                'status' => $staticRule === null ? self::BLOCKED : self::ENFORCED,
                'provenBy' => $staticRule === null ? null : StructuralRulesTest::class,
                'note' => $staticRule === null
                    ? $blocked
                    : 'TC-037 ‡ rule '.$staticRule.', non-suppressible under TC-038 ‡. ADM-199 ‡ requires it '
                        .'in every environment, and a static rule runs wherever the build does. ADM-204 ‡: '
                        .'with rule '.($staticRule === 5 ? '10' : '5').' this is what makes SRS-RISK-003 structural.',
            ];
        }

        return $register;
    }

    /**
     * One of CMP-DOC-09 §17.1's nine. None is non-suppressible.
     *
     * A method rather than a closure so that the shape is checked where it is
     * built: PHPStan honours a docblock on a method, and `list<string>` is the
     * whole of what `TC-008`'s statement-identifier rule needs to hold by type.
     *
     * @param  list<string>  $verifies
     * @return array<string, Obligation>
     */
    private static function backendObligation(
        int $n,
        string $obligation,
        array $verifies,
        int $level,
        string $technique,
        string $status,
        ?string $provenBy = null,
        string $note = '',
    ): array {
        return [
            'BE-17.1/'.$n => [
                'source' => 'CMP-DOC-09',
                'section' => '§17.1',
                'obligation' => $obligation,
                'verifies' => $verifies,
                'level' => $level,
                'suppressible' => true,
                'technique' => $technique,
                'status' => $status,
                'provenBy' => $provenBy,
                'note' => $note,
            ],
        ];
    }

    /**
     * One of CMP-DOC-13 §19.1's fourteen.
     *
     * @param  list<string>  $verifies
     * @return array<string, Obligation>
     */
    private static function securityCheck(
        int $n,
        string $obligation,
        array $verifies,
        int $level,
        string $technique,
        string $status,
        ?string $provenBy = null,
        string $note = '',
    ): array {
        return [
            'SEC-19.1/'.$n => [
                'source' => 'CMP-DOC-13',
                'section' => '§19.1',
                'obligation' => $obligation,
                'verifies' => $verifies,
                'level' => $level,
                // SEC-232 ‡, verbatim: "Checks 1 to 7 shall be non-suppressible."
                'suppressible' => $n > 7,
                'technique' => $technique,
                'status' => $status,
                'provenBy' => $provenBy,
                'note' => $note,
            ],
        ];
    }

    /**
     * The per-source counts CMP-DOC-18 Appendix C states.
     *
     * `TC-006` makes a mismatch a **documentation defect**, so this is asserted
     * against rather than derived — a register that counted itself would agree
     * with itself however wrong it was.
     *
     * @return array<string, array{count: int, nonSuppressible: int}>
     */
    public static function appendixCCounts(): array
    {
        return [
            'BE-17.1' => ['count' => 9, 'nonSuppressible' => 0],
            'BE-17.2' => ['count' => 8, 'nonSuppressible' => 3],
            'API-16' => ['count' => 3, 'nonSuppressible' => 0],
            'DB-15' => ['count' => 21, 'nonSuppressible' => 0],
            'SEC-19.1' => ['count' => 14, 'nonSuppressible' => 7],
            'PAY-16' => ['count' => 12, 'nonSuppressible' => 3],
            'GPS-14' => ['count' => 10, 'nonSuppressible' => 3],
            'NOTIF-13' => ['count' => 10, 'nonSuppressible' => 4],
            'ADM-16' => ['count' => 12, 'nonSuppressible' => 5],
        ];
    }
}
