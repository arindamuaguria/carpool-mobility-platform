<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\Authorisation\AuthorisationPolicy;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Infrastructure\Authorisation\EvidentialAuthorisationRefusals;
use Cmp\Infrastructure\Laravel\Providers\AuthorisationServiceProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use SplFileInfo;

/**
 * CMP-IMP-033 — the structural half of one authorisation path.
 *
 * `SADR-06` makes a single evaluation the whole defence of `TB-1` and `TB-2`, and
 * rejected the alternative in its own words: *"two paths diverge, and the one
 * used less is the one that rots."* A second path is not something a reviewer
 * will reliably notice, so the build fails on one.
 */
final class AuthorisationRulesTest extends TestCase
{
    /**
     * `SEC-054` ‡ / `BE-180` ‡: authorisation is **not** implemented in transport
     * middleware alone. The interface layer may authenticate; it may not decide.
     */
    private const INTERFACE_DIRECTORY = 'src/Interface/';

    /**
     * Types that would mean an entitlement came from a request rather than from
     * platform state (`SEC-045` ‡, `BE-181` ‡).
     */
    private const REQUEST_TYPES = ['Request', 'Session', 'Token', 'Jwt', 'Cookie', 'Header'];

    public function test_the_interface_layer_never_decides_an_authorisation(): void
    {
        // SEC-054 ‡ / BE-180 ‡. SADR-06 rejected middleware-only authorisation
        // because "a queue worker or a Filament resource bypassing the HTTP stack
        // would bypass authorisation entirely."
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_starts_with($relative, self::INTERFACE_DIRECTORY)) {
                continue;
            }

            foreach (['Authoriser', 'AuthorisationPolicy', 'AuthorisationRule'] as $decider) {
                if (str_contains($contents, $decider)) {
                    $offenders[] = $relative.' → '.$decider;
                }
            }
        }

        self::assertSame([], $offenders, 'SEC-054 ‡: the interface layer authenticates; it does not decide.');
    }

    public function test_the_authorisation_namespace_takes_nothing_from_a_request(): void
    {
        // SEC-045 ‡: "A session shall carry no authorisation claim; entitlement
        // shall be evaluated against platform state on every request."
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_starts_with($relative, 'src/Application/Shared/Authorisation/')) {
                continue;
            }

            foreach (self::REQUEST_TYPES as $type) {
                if (preg_match('/\b'.$type.'\b/', $contents) === 1 && ! str_contains($contents, 'on every request')) {
                    $offenders[] = $relative.' → '.$type;
                }
            }
        }

        self::assertSame([], $offenders, 'SEC-045 ‡: an actor is resolved from platform state, never from a claim.');
    }

    public function test_authorisation_happens_in_the_application_service_base(): void
    {
        // BE-044 / SEC-053 ‡: evaluated before the domain is invoked, on every
        // caller. Putting it in the base is what makes it impossible to omit.
        $contents = file_get_contents(self::basePath('src/Application/Shared/ApplicationService.php'));
        self::assertIsString($contents);

        self::assertStringContainsString('$this->authoriser->authorise(', $contents);

        // final, so a service cannot replace the path that authorises.
        $execute = new ReflectionMethod(ApplicationService::class, 'execute');
        self::assertTrue($execute->isFinal(), 'A service must not be able to override execute() and skip authorisation.');
    }

    public function test_the_evaluation_cannot_hand_back_an_exemption(): void
    {
        // SEC-010 ‡ / SEC-062 ‡ / SD-04: "An operator gains capability, never
        // exemption." A void return has nothing an exemption could ride on, and a
        // permitted operation still runs every domain invariant afterwards.
        $authorise = new ReflectionMethod(Authoriser::class, 'authorise');

        self::assertSame('void', (string) $authorise->getReturnType());
    }

    public function test_the_policy_states_a_rule_for_every_operation_and_no_others(): void
    {
        // SEC-055 ‡: an operation with no stated rule is refused. The policy
        // holds exactly the rules for the operations that exist — three from
        // CMP-IMP-053, CMP-IMP-056 and CMP-IMP-057, and four from UC-048 — and a
        // rule ahead of its operation would permit nothing while reviewing as
        // though something had been decided.
        self::assertSame(
            ['sessions.establish', 'sessions.current.terminate', 'sessions.current.refresh'],
            array_keys(AuthorisationServiceProvider::sessionRules()),
        );

        self::assertSame(
            [
                'profile.emergency_contacts.read',
                'profile.emergency_contacts.nominate',
                'profile.emergency_contacts.amend',
                'profile.emergency_contacts.remove',
            ],
            array_keys(AuthorisationServiceProvider::emergencyContactRules()),
        );

        // And the policy is those two registers and nothing else. A rule
        // reachable without appearing in one of them would be a rule no
        // reviewer would find in the one place SADR-06 says to look.
        self::assertSame(
            count(AuthorisationServiceProvider::sessionRules())
                + count(AuthorisationServiceProvider::emergencyContactRules()),
            AuthorisationServiceProvider::policy()->count(),
        );
    }

    public function test_no_stated_rule_depends_on_the_undecided_role_set(): void
    {
        // SEC-063: "Role definitions and their capabilities are [TBD – Business
        // Decision Required]; the mechanism is specified and the role set is
        // not." BAD-DEC-006 is open and ADM-168 records that the administrative
        // unit cannot start without it.
        //
        // So the role set is still empty, and every rule stated so far turns on
        // SEC-066 ‡ — being a party to the record — which needs no role at all.
        // A rule requiring a capability would be a rule nobody could ever satisfy,
        // and it would look like an implementation.
        self::assertSame([], AuthorisationServiceProvider::roles());

        $stated = [
            ...AuthorisationServiceProvider::sessionRules(),
            ...AuthorisationServiceProvider::emergencyContactRules(),
        ];

        foreach ($stated as $operation => $rule) {
            self::assertNull($rule->capability(), $operation.' must not require a capability while SEC-063 is open.');
            self::assertNull($rule->requiresRoleOfKind(), $operation.' must not require a role kind while SEC-063 is open.');
            self::assertTrue($rule->requiresParty(), $operation.' turns on SEC-066 ‡, being a party to the record.');
            self::assertFalse($rule->altersEntitlement(), $operation.' alters what the caller holds, not what they may do.');
        }
    }

    public function test_a_policy_absent_of_an_operation_states_no_rule_for_it(): void
    {
        // The deny-by-default behaviour is a property of the policy, not of the
        // caller remembering to check.
        $policy = AuthorisationPolicy::of([]);

        self::assertFalse($policy->statesARuleFor(Operation::named('test.anything')));
        self::assertNull($policy->ruleFor(Operation::named('test.anything')));
    }

    public function test_the_adapter_never_decides_what_a_representation_discloses(): void
    {
        // CMP-IMP-466. API-050 ‡: "entitlement shall be evaluated in the
        // application layer and never by the adapter." API-049 ‡ is what it
        // protects — a representation includes only the fields the caller's
        // relationship entitles them to — and AADR-08 chose relationship over
        // role because two passengers on the same ride are entitled to different
        // things about each other.
        //
        // An adapter that filtered fields itself would be a second disclosure
        // decision, and SADR-06's objection applies unchanged: two paths diverge,
        // and the one used less is the one that rots.
        $offenders = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (! str_starts_with($relative, self::INTERFACE_DIRECTORY)) {
                continue;
            }

            foreach (['->holds(', '->holdsRoleOfKind(', 'partyReferences(', 'entitlementSubject('] as $decision) {
                if (str_contains($contents, $decision)) {
                    $offenders[] = $relative.' → '.$decision;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'API-050 ‡: the adapter serialises what it is given; it does not decide what the caller may see.',
        );
    }

    public function test_the_disclosure_mechanism_is_recorded_as_awaiting_its_first_resource(): void
    {
        // The honest half of CMP-IMP-466. API-049 ‡'s per-relationship field
        // sets, API-055's marking of which fields are relationship-dependent and
        // API-056's rule that absence must not reveal the value are all
        // properties of a representation, and the surface serves no resource
        // representation yet — CMP-DOC-10 §11's catalogue needs application
        // services and BE-017's nine aggregates are unbuilt.
        //
        // The rule above lands first, so the first representation is written
        // against a check that already exists. This assertion is what fails when
        // one is added, so the mechanism is built then rather than forgotten.
        $representations = array_keys(array_filter(
            self::sourceFiles(),
            static fn (string $relative): bool => str_starts_with($relative, self::INTERFACE_DIRECTORY.'Rest/Representation'),
            ARRAY_FILTER_USE_KEY,
        ));

        self::assertSame([], $representations, 'A representation now exists; API-049 ‡ and API-055 need building with it.');
    }

    public function test_a_refusal_is_recorded_evidentially_and_by_nothing_else(): void
    {
        // SEC-057 ‡: every refused authorisation is recorded, and BE-202 says
        // operational logging "shall not substitute for" the evidential log. A
        // second implementation of the contract would be a place a refusal could
        // be recorded without reaching `ev_`, which is the shape of the interim
        // this replaced.
        $implementations = [];

        foreach (self::sourceFiles() as $relative => $contents) {
            if (str_contains($contents, 'implements RecordsAuthorisationRefusals')) {
                $implementations[] = $relative;
            }
        }

        self::assertSame(['src/Infrastructure/Authorisation/EvidentialAuthorisationRefusals.php'], $implementations);

        // And it records evidence rather than merely logging: the constructor
        // takes the writing contract, so a build that removed the evidential
        // write would not type-check into this shape.
        $parameters = (new ReflectionMethod(EvidentialAuthorisationRefusals::class, '__construct'))->getParameters();

        self::assertSame(RecordsEvidence::class, (string) $parameters[0]->getType());
    }

    public function test_the_recorder_is_called_before_the_refusal_is_raised(): void
    {
        // FRD-FR-248 ‡: an outcome is not reported where its record could not be
        // written. Authoriser records first and raises second, so a write failure
        // replaces the refusal instead of accompanying it. Asserted on the source
        // because the ordering is the property, and a test that only observed a
        // successful write could not see it.
        $source = file_get_contents(self::basePath('src/Application/Shared/Authorisation/Authoriser.php'));
        self::assertIsString($source);

        $recordAt = strpos($source, '$this->refusals->record(');
        $raiseAt = strpos($source, 'throw AuthorisationRefused::because(');

        self::assertIsInt($recordAt);
        self::assertIsInt($raiseAt);
        self::assertLessThan($raiseAt, $recordAt, 'SEC-057 ‡: the record exists before the refusal is reported.');
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
