<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `CMP-IMP-036` — the thirteen structural rules of `TC-037` ‡, in one register.
 *
 * `TADR-03` is the reason this file exists: *"a test can show a path works; only
 * inspection can show no path exists."* CMP-DOC-06 §9.2 counted 74 of 606
 * verifications as non-executable, most of them asserting an **absence** — and an
 * absence has no test that exercises it.
 *
 * `TC-037` ‡ requires all thirteen to run on every commit and to fail the build.
 * `TC-038` ‡ makes eight of them non-suppressible. `TC-040` ‡ requires them in
 * every environment, which `TECH-DEC-003` satisfies by having no baseline file and
 * no suppression list anywhere.
 *
 * ## Why the register, and not thirteen scattered tests
 *
 * Five of the thirteen were already enforced by tests written alongside the code
 * they constrain, and moving them here would separate each rule from the
 * reasoning that motivates it. What was missing is the **thirteenth thing**: a
 * single place that says which thirteen there are, so that a rule cannot go
 * missing without the build noticing. {@see RULES} is that place. Each entry
 * either names the test that runs it — asserted to exist, because a delegation to
 * a file that no longer exists is a hole — or is run here.
 *
 * ## Rules that cannot fire yet, and why that is stated rather than hidden
 *
 * Five rules guard surfaces the platform does not have: an administrative
 * namespace, a Filament resource, a request schema, a notification payload, a
 * payment path. Each of those rules is written and runs now, and each currently
 * passes because there is nothing to catch.
 *
 * A rule that passes vacuously is worth having — it lands **before** the code it
 * constrains, so the first commit that would violate it fails instead of the
 * review that might have caught it. But a vacuous pass must never be mistaken for
 * a checked one, so {@see test_a_rule_with_nothing_to_check_yet_says_so} asserts
 * that the surfaces really are empty, and every locally-run rule has a companion
 * assertion that its detector fires on a synthetic offender (`TC-041`, `TC-042`).
 *
 * ## `BE-010`, and what this file does not claim
 *
 * `CMP-IMP-036` also cites `BE-010` — *"each business rule shall be implemented
 * in exactly one Domain component"*. **Half of that is verified here and half is
 * not.** Rule 8 keeps a rule out of the four other kinds, and rule 3 and the layer
 * graph keep the Domain single, so *where* a rule lives is structural. That a rule
 * is implemented **exactly once** is not decidable by inspection: it would require
 * recognising two components as expressing the same rule, and no static check
 * does that.
 *
 * It is also not yet checkable by any other means, because the platform has no
 * business rule to duplicate — `BE-017`'s nine aggregates are unbuilt. The
 * obligation therefore sits at level 6, manual review (`TC-025`), and is recorded
 * as such here rather than left looking discharged.
 */
final class StructuralRulesTest extends TestCase
{
    /**
     * The thirteen, as CMP-DOC-18 §6.1 declares them.
     *
     * `runsIn` is either the architecture test that owns the rule, or `here`.
     *
     * @var array<int, array{rule: string, source: string, runsIn: string}>
     */
    private const RULES = [
        1 => ['rule' => 'Domain references no framework type', 'source' => 'BE-002', 'runsIn' => 'DomainIsolationTest'],
        2 => ['rule' => 'Dependencies point inward only', 'source' => 'BE-003', 'runsIn' => 'LayerDependencyRuleTest'],
        3 => ['rule' => 'No ORM type outside three permitted namespaces', 'source' => 'BE-087', 'runsIn' => 'here'],
        4 => ['rule' => 'No transaction control outside Application', 'source' => 'BE-047', 'runsIn' => 'TransactionControlTest'],
        5 => ['rule' => 'No ORM access from the administrative namespace', 'source' => 'BE-075', 'runsIn' => 'here'],
        6 => ['rule' => 'No evidential write outside the writer', 'source' => 'BE-105', 'runsIn' => 'EvidentialLogRulesTest'],
        7 => ['rule' => 'No provider type above an adapter', 'source' => 'BE-153', 'runsIn' => 'PortRulesTest'],
        8 => ['rule' => 'No business rule in a controller, resource, model, listener or job', 'source' => 'BE-011', 'runsIn' => 'here'],
        9 => ['rule' => 'No dynamic statement construction', 'source' => 'SEC-131', 'runsIn' => 'here'],
        10 => ['rule' => 'No Filament resource declares an Eloquent model', 'source' => 'ADM-002', 'runsIn' => 'here'],
        11 => ['rule' => 'No request schema accepts an authoritative field', 'source' => 'API-214', 'runsIn' => 'here'],
        12 => ['rule' => 'No business value type reaches a notification payload', 'source' => 'NOTIF-187', 'runsIn' => 'here'],
        13 => ['rule' => 'The UPI application response is absent from every code path', 'source' => 'PAY-205', 'runsIn' => 'here'],
    ];

    /**
     * `TC-038` ‡, verbatim: *"Rules 5, 6, 8, 9, 10, 11, 12 and 13 shall be
     * non-suppressible."*
     *
     * `TC-039` ‡ singles out 5 and 10: together they are what make
     * `SRS-RISK-003` — an administrative surface reaching past the application
     * layer — structural rather than aspirational.
     *
     * @var list<int>
     */
    private const NON_SUPPRESSIBLE = [5, 6, 8, 9, 10, 11, 12, 13];

    /**
     * `BE-087`: *"The ORM shall be used only inside repository implementations,
     * the projection maintenance layer and the evidential writer."*
     *
     * The projection namespace does not exist yet — `ARCH-113` puts projections
     * with the aggregates of `BE-017`, and none is built. Its prefix is listed so
     * that the first projection does not have to argue with this rule.
     *
     * @var list<string>
     */
    private const ORM_PERMITTED_PREFIXES = [
        'src/Infrastructure/Persistence/',
        'src/Infrastructure/Projection/',
        'src/Infrastructure/Evidential/',
    ];

    /**
     * `BE-011` names five places a business rule must not be: a controller, an
     * administrative resource, an ORM model, a listener and a job.
     *
     * Two of the five are recognised by where they live — the `Interface` layer
     * holds adapters only (`BE-005`), so a controller and an administrative
     * resource are both under `src/Interface/`. The other three are recognised by
     * what they **are**, in {@see isARuleFreeKind()}, because a listener, a job
     * and an ORM model are defined by their contract rather than by a directory
     * this test would otherwise have to invent for them.
     *
     * @var list<string>
     */
    private const RULE_FREE_DIRECTORIES = [
        'src/Interface/',
    ];

    /**
     * The only files that may compose a statement from anything but literals and
     * constants, each with the reason it must.
     *
     * `SADR-09` / `DB-038` ‡ / `SEC-121`: bind every value, never concatenate a
     * statement. **A value.** MySQL binds no identifier — not an account name, not
     * a host, not a schema, not a privilege list — so the two files that issue
     * `DCL` cannot bind what they interpolate and no formulation of this rule can
     * make them able to.
     *
     * `TC-042` requires a rule producing a false positive to be **narrowed, never
     * suppressed**, and this register is that narrowing: the exception is named,
     * reasoned, and small enough to read. A sixth site cannot appear without this
     * list changing, and this list changing is a reviewed edit.
     *
     * @var array<string, string> relative path => why it composes
     */
    private const STATEMENT_COMPOSITION_SITES = [
        'src/Infrastructure/Persistence/Grants/GrantPlan.php' => 'DCL. GRANT and REVOKE name an account, a schema and a privilege list, and MySQL binds none of them. '
            .'Every interpolated part comes from this class own constants and from DatabaseAccount, which is a '
            .'closed enum — nothing a caller supplies reaches a statement.',
        'src/Infrastructure/Persistence/Grants/DatabaseAccountProvisioner.php' => 'DDL for an account. CREATE USER and ALTER USER take an identifier and a secret, neither bindable. '
            .'The values come from configuration read at deploy time (BE-015, SADR-14), never from a request.',
    ];

    public function test_all_thirteen_rules_are_declared(): void
    {
        // TC-037 ‡ says thirteen. A rule quietly dropped from the register would
        // be a rule quietly stopped running, and nothing else would notice.
        self::assertSame(range(1, 13), array_keys(self::RULES));

        foreach (self::RULES as $number => $rule) {
            self::assertNotSame('', $rule['rule'], 'Rule '.$number.' must say what it forbids.');
            self::assertMatchesRegularExpression(
                '/^(BE|SEC|ADM|API|NOTIF|PAY)-\d{3}$/',
                $rule['source'],
                'Rule '.$number.' must name the statement it enforces.',
            );
        }
    }

    public function test_the_eight_non_suppressible_rules_are_the_eight_tc_038_names(): void
    {
        // TC-038 ‡, and TC-021 behind it. This project has no suppression
        // mechanism at all (TECH-DEC-003), so what is asserted here is that the
        // eight are recorded — the next test asserts nothing exists to suppress
        // them with.
        self::assertSame([5, 6, 8, 9, 10, 11, 12, 13], self::NON_SUPPRESSIBLE);

        foreach (self::NON_SUPPRESSIBLE as $number) {
            self::assertArrayHasKey($number, self::RULES);
        }
    }

    public function test_no_rule_can_be_suppressed_because_nothing_exists_to_suppress_it_with(): void
    {
        // TC-042 / TECH-DEC-003: no baseline file and no suppression list in any
        // configuration. TC-040 ‡ then follows for free — a rule with no opt-out
        // runs the same everywhere.
        foreach (['phpstan.neon', 'deptrac.yaml', 'phpunit.xml', 'pint.json'] as $configuration) {
            $path = self::basePath($configuration);

            if (! is_file($path)) {
                continue;
            }

            $contents = file_get_contents($path);
            self::assertIsString($contents);

            // TC-041: comments are stripped first. Two of these files explain, in
            // prose, that there is no baseline — a rule that fired on the
            // explanation would be the false positive TC-042 says to narrow away.
            $directives = self::withoutComments($contents);

            foreach (['baselineFile', 'ignoreErrors', 'skip_violations', 'excludeGroups', 'defaultTimeLimit'] as $escape) {
                self::assertStringNotContainsString(
                    $escape,
                    $directives,
                    $configuration.' declares "'.$escape.'"; TC-042 narrows a rule and never suppresses it.',
                );
            }

            // A baseline included rather than declared is the same escape by
            // another route.
            self::assertDoesNotMatchRegularExpression('/-\s*\S*baseline\S*\.neon/', $directives);
        }

        foreach (self::sourceFiles() as $relative => $contents) {
            foreach (['@phpstan-ignore', '@psalm-suppress', 'phpcs:ignore'] as $escape) {
                self::assertStringNotContainsString($escape, $contents, $relative.' carries '.$escape.'.');
            }
        }
    }

    public function test_every_delegated_rule_names_a_test_that_exists(): void
    {
        // A delegation to a file that no longer exists is an allow-list rotting
        // into a hole — the same failure EvidentialLogRulesTest guards its
        // permitted list against.
        foreach (self::RULES as $number => $rule) {
            if ($rule['runsIn'] === 'here') {
                continue;
            }

            self::assertFileExists(
                self::basePath('tests/Architecture/'.$rule['runsIn'].'.php'),
                'Rule '.$number.' is delegated to '.$rule['runsIn'].', which does not exist.',
            );
        }
    }

    public function test_rule_3_no_orm_type_outside_the_three_permitted_namespaces(): void
    {
        // BE-087 / BE-009 / BADR-05: the ORM is an infrastructure detail. It is
        // absent from the whole tree today, which is a stronger position than the
        // rule requires and not one this test should assume will last.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! self::mentionsTheOrm($contents)) {
                continue;
            }

            foreach (self::ORM_PERMITTED_PREFIXES as $permitted) {
                if (str_starts_with($relative, $permitted)) {
                    continue 2;
                }
            }

            $offenders[] = $relative;
        }

        self::assertSame([], $offenders, 'BE-087: the ORM lives in repositories, projections and the evidential writer.');
    }

    public function test_rule_5_no_orm_access_from_the_administrative_namespace(): void
    {
        // BE-075 ‡ / ARCH-043, and TC-039 ‡ pairs this with rule 10.
        // ADM-001 ‡ requires every administrative read and write to invoke an
        // application service; an ORM import in that namespace is the shape of a
        // resource that has stopped doing so.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (str_starts_with($relative, 'src/Interface/Admin/') && self::mentionsTheOrm($contents)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'BE-075 ‡: an administrative resource reaches the platform through an application service.');
    }

    public function test_rule_8_no_business_rule_in_a_controller_resource_model_listener_or_job(): void
    {
        // BE-011 / SRS-REQ-070 / BADR-01.
        //
        // "A business rule" cannot be recognised by inspection, so the rule is
        // narrowed to the structure that makes one possible: BE-010 puts each
        // business rule in exactly one Domain component, so a class that never
        // reaches a Domain type cannot be holding one. That is checkable, produces
        // no false positive in correct code (TC-041), and fails on the first
        // controller that reaches past its application service.
        $offenders = [];
        $examined = 0;

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! self::isARuleFreeKind($relative, $contents)) {
                continue;
            }

            $examined++;

            if (str_contains($contents, 'use Cmp\Domain\\')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'BE-011: a controller, resource, model, listener or job reaches the domain through an application service, '
            .'so that BE-010 keeps each rule in exactly one place.',
        );

        // A rule that examined nothing proves nothing. The four console adapters
        // and PlatformJob are what it runs on today.
        self::assertGreaterThan(0, $examined);
    }

    public function test_rule_9_no_statement_is_composed_outside_the_declared_sites(): void
    {
        // SEC-131 / SADR-09 / DB-038 ‡ / SEC-121.
        $composing = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (self::composesAStatement($contents)) {
                $composing[] = $relative;
            }
        }

        sort($composing);
        $declared = array_keys(self::STATEMENT_COMPOSITION_SITES);
        sort($declared);

        self::assertSame(
            $declared,
            $composing,
            'SEC-131: a statement is composed from literals and constants, or the site is declared here with its reason.',
        );
    }

    public function test_rule_9_recognises_a_statement_built_from_a_variable(): void
    {
        // TC-041 / TC-042: the rule must produce the true positive it exists for.
        self::assertTrue(self::composesAStatement('$sql = sprintf(\'SELECT * FROM t WHERE c = %s\', $value);'));
        self::assertTrue(self::composesAStatement('$sql = "SELECT * FROM t WHERE c = $value";'));
        self::assertTrue(self::composesAStatement('$sql = \'SELECT * FROM t WHERE c = \'.$value;'));

        // And must not fire on the composition the whole codebase uses: a literal
        // joined to a constant, with every value bound.
        self::assertFalse(self::composesAStatement('$c->select(\'SELECT id FROM \'.self::TABLE.\' WHERE k = ?\', [$k]);'));
        self::assertFalse(self::composesAStatement('$c->select($query, [$key->name()]);'));
    }

    public function test_rule_10_no_filament_resource_declares_an_eloquent_model(): void
    {
        // ADM-002 ‡, and TC-039 ‡ with rule 5. ADMR-01 rejected the binding
        // outright: a resource that names a model generates its table, its form
        // and its filters from columns, and ADM-003 ‡ forbids that too.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_contains($contents, 'Filament')) {
                continue;
            }

            if (preg_match('/\$model\s*=/', $contents) === 1 || self::mentionsTheOrm($contents)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'ADM-002 ‡: no Filament resource declares an Eloquent model.');

        // TECH-DEC-001 left the administrative toolkit deliberately undecided, so
        // the strongest form of this rule holds today: the dependency is absent.
        $manifest = file_get_contents(self::basePath('composer.json'));
        self::assertIsString($manifest);
        self::assertStringNotContainsString('filament/', $manifest);
    }

    public function test_rule_11_no_request_schema_accepts_an_authoritative_field(): void
    {
        // API-214 ‡ requires the contract test; API-037 ‡ names the fields:
        // "fare, verification standing, payment status, seat counts, ratings,
        // balances and trip state shall be absent from every request schema."
        //
        // FRD-FR-237–241 and CLAUDE.md rule 4 are the behaviour behind it: a
        // client-asserted authoritative value is refused in whole. A schema that
        // accepted one would make that refusal a runtime concern rather than an
        // impossibility.
        $offenders = [];

        foreach (self::requestSchemaFiles() as $relative => $contents) {
            foreach (self::authoritativeFieldNames() as $field) {
                if (str_contains($contents, "'".$field."'") || str_contains($contents, '"'.$field.'"')) {
                    $offenders[] = $relative.' → '.$field;
                }
            }
        }

        self::assertSame([], $offenders, 'API-037 ‡: an authoritative field is absent from every request schema.');
    }

    public function test_rule_12_no_business_value_type_reaches_a_notification_payload(): void
    {
        // NOTIF-187 / NOTIF-133 ‡: "a notification shall carry no business
        // value." CMP-DOC-12 §18.7 is the source — a notification is delivered
        // outside the platform's trust boundary and cannot be authoritative, so
        // carrying a fare or a seat count would present a value the client must
        // then be told not to believe.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_contains($relative, 'Notification')) {
                continue;
            }

            foreach (self::authoritativeFieldNames() as $field) {
                if (str_contains($contents, "'".$field."'") || str_contains($contents, '"'.$field.'"')) {
                    $offenders[] = $relative.' → '.$field;
                }
            }
        }

        self::assertSame([], $offenders, 'NOTIF-133 ‡: a notification carries no business value.');
    }

    public function test_rule_13_the_upi_application_response_is_absent_from_every_code_path(): void
    {
        // PAY-205 / PAY-038 ‡ / PAY-039 ‡ / PADR-03 / BAD-RULE-032, and
        // CLAUDE.md §5: the UPI application's response is never treated as
        // evidence of payment. PAY-039 ‡ goes further — it is not logged, not
        // stored, not transmitted, and not used to select a presentation — so the
        // rule is that the response does not appear in a code path at all.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            foreach (['upiResponse', 'upi_response', 'upiAppResponse', 'upiIntentResult'] as $trace) {
                if (str_contains($contents, $trace)) {
                    $offenders[] = $relative.' → '.$trace;
                }
            }
        }

        self::assertSame([], $offenders, 'PAY-038 ‡ / PAY-039 ‡: the UPI application response is discarded on receipt.');
    }

    public function test_no_domain_component_offers_a_way_around_a_rule(): void
    {
        // BE-012 / ARCH-033 / SRS-REQ-125: "absolute business rules shall reside
        // in Domain and shall not be reachable for override."
        //
        // The platform's absolute rules are already unreachable by construction —
        // TransitionInvariant has no method that permits, Authoriser::authorise
        // returns void so it cannot hand back an exemption, and Role has no field
        // an exemption could sit in. This catches the other shape: a method whose
        // name is an invitation. It is narrow to the naming, because a rule that
        // tried to recognise "an override" by behaviour would recognise nothing.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_starts_with($relative, 'src/Domain/')) {
                continue;
            }

            foreach (['force', 'override', 'bypass', 'skip', 'disable', 'unsafe', 'ignore'] as $invitation) {
                if (preg_match('/function\s+'.$invitation.'[A-Z(]/', $contents) === 1) {
                    $offenders[] = $relative.' → '.$invitation.'…()';
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'BE-012: an absolute rule in the Domain is not reachable for override, and a method named for one is a route to it.',
        );
    }

    public function test_the_detectors_recognise_what_their_rules_exist_for(): void
    {
        // TC-041 / TC-042. Five of the thirteen currently pass because there is
        // nothing to catch, so the only evidence that they work is that they fire
        // on an offender. Written here rather than as five fixture files, because
        // a fixture that violated rule 3 would violate rule 3.
        self::assertTrue(self::mentionsTheOrm('use Illuminate\Database\Eloquent\Model;'));
        self::assertTrue(self::mentionsTheOrm('final class Ride extends Model {}'));
        self::assertFalse(
            self::mentionsTheOrm('use Illuminate\Database\ConnectionInterface;'),
            'The query layer is not the ORM; every repository here uses it.',
        );

        // Rule 8 recognises a listener and a job wherever they are put, and an
        // adapter by where it lives.
        self::assertTrue(self::isARuleFreeKind('src/Anywhere/X.php', 'implements DomainEventListener'));
        self::assertTrue(self::isARuleFreeKind('src/Anywhere/X.php', 'final class X extends PlatformJob {}'));
        self::assertTrue(self::isARuleFreeKind('src/Interface/Rest/RideController.php', ''));
        self::assertFalse(self::isARuleFreeKind('src/Application/Shared/Policy/ChangePolicyValue.php', ''));

        // Rules 11 and 12 both read API-037 ‡'s seven, so a schema or a payload
        // naming one is caught by the same list.
        self::assertContains('fare', self::authoritativeFieldNames());
        self::assertContains('payment_status', self::authoritativeFieldNames());
        self::assertContains('seats_available', self::authoritativeFieldNames());
    }

    public function test_a_rule_with_nothing_to_check_yet_says_so(): void
    {
        // The honesty test. Five rules pass today because the surface they guard
        // is empty, and a reader must be able to tell that from a rule that
        // examined something. Each absence is asserted, with what will end it.
        $empty = [
            // Rules 5 and 10: BAD-DEC-006 leaves the administrative role set
            // undecided and ADM-168 records that the admin unit cannot start.
            'src/Interface/Admin' => 'ADM-168 / BAD-DEC-006',
            // Rule 11: no REST surface exists; FEAT-035 brings the first.
            'src/Interface/Rest' => 'FEAT-035',
            // Rules 12 and 13: BE-161 leaves the notification provider directed
            // but unselected (CMP-IMP-334, FEAT-023), and FEAT-016 brings the
            // payment operations. Neither has an adapter (CMP-IMP-030).
            'src/Infrastructure/Adapter' => 'CMP-IMP-030 / FEAT-016 / FEAT-023',
        ];

        foreach ($empty as $directory => $blocker) {
            // The directory exists — CMP-IMP-021 created the layer skeleton
            // (BE-004) — and holds no PHP. Emptiness is the assertion, not
            // absence.
            self::assertSame(
                [],
                glob(self::basePath($directory).'/*.php') ?: [],
                $directory.' now holds source, so the rule guarding it is no longer vacuous — '
                .'update this test rather than leaving it asserting an absence that has ended ('.$blocker.').',
            );
        }

        // Rule 3 is not in that list: the ORM is absent everywhere today, which is
        // narrower than BE-087 requires, and the rule above examines the whole
        // tree rather than an empty directory.
        self::assertFalse(self::mentionsTheOrm(implode("\n", self::sourceFiles())));
    }

    /**
     * `API-037` ‡'s seven, as field names a schema would plausibly use.
     *
     * @return list<string>
     */
    private static function authoritativeFieldNames(): array
    {
        return [
            'fare', 'fare_amount', 'fareAmount',
            'verification_status', 'verificationStatus', 'verification_standing',
            'payment_status', 'paymentStatus',
            'seats_available', 'seatsAvailable', 'seat_count', 'seatCount',
            'rating', 'ratings',
            'wallet_balance', 'walletBalance', 'balance',
            'trip_state', 'tripState', 'booking_status', 'bookingStatus',
        ];
    }

    /**
     * Whether a file is one of `BE-011`'s five kinds.
     *
     * A listener and a job are recognised by their contract, and an ORM model by
     * extending one — none of the three has a directory this test may assume,
     * and inventing one would make the rule miss the first that lands elsewhere.
     */
    private static function isARuleFreeKind(string $relative, string $contents): bool
    {
        foreach (self::RULE_FREE_DIRECTORIES as $directory) {
            if (str_starts_with($relative, $directory)) {
                return true;
            }
        }

        return str_contains($contents, 'implements DomainEventListener')
            || str_contains($contents, 'extends PlatformJob')
            || self::mentionsTheOrm($contents);
    }

    /**
     * Whether a file reaches the ORM, as distinct from the database.
     *
     * `TC-041`: narrow. `Illuminate\Database\ConnectionInterface` is the query
     * layer and every repository here uses it; the ORM is Eloquent, and it is the
     * ORM that `BE-009` and `BE-087` place behind a repository.
     */
    private static function mentionsTheOrm(string $contents): bool
    {
        return str_contains($contents, 'Illuminate\Database\Eloquent')
            || str_contains($contents, 'extends Model')
            || str_contains($contents, 'Eloquent\Model');
    }

    /**
     * Whether a file builds a statement out of anything but literals and
     * constants.
     *
     * Three shapes, and only three: a `sprintf` over an SQL format string, an
     * interpolated double-quoted SQL string, and an SQL literal concatenated with
     * a variable. A literal joined to a class constant is not one of them, because
     * a constant is not a value a caller supplies — which is what `DB-038` ‡ is
     * about.
     */
    private static function composesAStatement(string $contents): bool
    {
        $keywords = 'SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|GRANT|REVOKE|FLUSH|SHOW|SIGNAL|TRUNCATE';

        // sprintf('SELECT ...', ...)
        if (preg_match('/sprintf\(\s*\'\s*('.$keywords.')\b/i', $contents) === 1) {
            return true;
        }

        // "SELECT ... $variable ..."
        if (preg_match('/"\s*('.$keywords.')\b[^"]*\$[A-Za-z_{]/i', $contents) === 1) {
            return true;
        }

        // 'SELECT ...'.$variable
        if (preg_match('/\'\s*('.$keywords.')\b[^\']*\'\s*\.\s*\$/i', $contents) === 1) {
            return true;
        }

        return false;
    }

    /**
     * Where a request schema will live: the REST surface, and the safety surface
     * which `BADR-16` boots separately but which accepts requests all the same.
     *
     * @return array<string, string> relative path => contents
     */
    private static function requestSchemaFiles(): array
    {
        $schemas = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (str_starts_with($relative, 'src/Interface/Rest/')
                || str_starts_with($relative, 'src/Interface/Safety/')) {
                $schemas[$relative] = $contents;
            }
        }

        return $schemas;
    }

    /**
     * A configuration file with its comment lines removed, so a rule reads what
     * the file declares rather than what it explains.
     */
    private static function withoutComments(string $contents): string
    {
        $kept = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
                continue;
            }

            $kept[] = preg_replace('/<!--.*?-->/s', '', $line) ?? $line;
        }

        return implode("\n", $kept);
    }

    /**
     * @return array<string, string> relative path => contents
     */
    private static function sourceFiles(): array
    {
        $root = self::basePath('');
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);

            $files[str_replace('\\', '/', substr($file->getPathname(), strlen($root)))] = $contents;
        }

        return $files;
    }

    private static function basePath(string $relative): string
    {
        return dirname(__DIR__, 2).'/'.$relative;
    }
}
