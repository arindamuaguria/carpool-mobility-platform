<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Application\Shared\ApplicationService;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\InterfaceObligations;

/**
 * `API-002` ‡ and `API-003` ‡ — what an adapter may do, checked.
 *
 * Both were `absent` in {@see InterfaceObligations} when that register was
 * first written: their subject exists — nine operations across two surfaces — and
 * nothing asserted them. Writing the register is what found them, which is what a
 * register is for.
 *
 * - **`API-002` ‡**: *"Every operation shall invoke exactly one application
 *   service operation."* `AADR-01` and `BE-013` are behind it: an adapter that
 *   called two services would be composing a use case in the interface layer,
 *   where `BE-041` says exactly one application service realises one.
 *
 *   **`CC-053` corrected this rule twice.** It read a method body by looking for a
 *   closing brace at four spaces, which runs past a promoted constructor's `) {}`
 *   and swallows the method after it — so **five of the eleven operations were
 *   never examined**, including the single operation of all three controllers that
 *   declare one. And it counted `->execute(`, which is how an
 *   {@see ApplicationService} is invoked and not how every
 *   application service is: `HealthController` calls `->now()` and
 *   `ConfigurationController` calls `->public()`, so once the missing operations
 *   became visible the old predicate judged them to invoke nothing. The rule now
 *   counts invocations on the **application collaborators the constructor takes**,
 *   which is the statement rather than one spelling of it. {@see ReadsMethods}
 *   carries the first fix for every rule in this directory.
 * - **`API-003` ‡**: *"No operation shall read persistence other than through an
 *   application service."* `StructuralRulesTest` rule 3 already keeps the **ORM**
 *   out of this layer, and that is narrower than the statement: a controller
 *   reaching the query layer directly carries no ORM type and would pass it.
 */
final class InterfaceStructureRulesTest extends TestCase
{
    use ReadsMethods;

    /**
     * Application services that are not {@see ApplicationService} subclasses, and
     * why each one is a service all the same.
     *
     * `API-002` ‡ speaks of *"an application service operation"*, not of a base
     * class. Both of these realise a use case over platform state; neither takes an
     * actor, because neither is reached through a session — so there is nothing for
     * `SADR-06`'s authorisation or `API-062` ‡'s idempotency to do, and extending
     * the base class would have meant inventing an actor to satisfy it.
     *
     * Declared rather than inferred, so that a collaborator's standing as a service
     * is a decision somebody wrote down. A new one that is neither a subclass nor
     * named here makes its operation appear to invoke nothing, and the rule fails —
     * which is the direction a closed list must fail in.
     *
     * @var array<string, string>
     */
    private const SERVICES_WITHOUT_AN_ACTOR = [
        'ReportPlatformHealth' => 'BE-203 / FRD-FR-255: the health indication is served outside the session, so '
            .'there is no actor to authorise. API-080 makes it unauthenticated.',
        'ServeConfiguration' => 'API-187 ‡: the client configuration is what a client needs before it has a '
            .'session, so it is served without one.',
    ];

    /**
     * The operations that invoke no application service, and the authority for it.
     *
     * One. `API-031` states that the domain is not versioned, so the supported
     * version range is a constant of the interface layer and there is no
     * application service that could know it — inventing one would be inventing a
     * domain concept in order to satisfy a rule about adapters. `VersionsController`
     * records the same reading in its own docblock.
     *
     * {@see test_each_exemption_is_still_the_operation_it_was_granted_for()} keeps
     * this honest: an entry that has quietly acquired a service invocation fails,
     * so the list cannot outlive its reason.
     *
     * @var array<string, string>
     */
    private const WITHOUT_A_SERVICE = [
        'src/Interface/Rest/Controller/VersionsController.php::__invoke()' => 'API-031: the domain is not '
            .'versioned, so no application service knows the supported range.',
    ];

    /**
     * Every operation the platform exposes.
     *
     * Stated, in the way `InterfaceObligationsTest`'s count of marked statements is
     * stated, because a rule that derives its own subject cannot tell "no operation
     * broke the rule" from "no operation was read". Five of these were invisible to
     * this file until `CC-053`, and the assertion that would have caught it is this
     * one.
     *
     * @var list<string>
     */
    private const OPERATIONS = [
        'src/Interface/Rest/Controller/ConfigurationController.php::__invoke()',
        'src/Interface/Rest/Controller/CurrentSessionController.php::destroy()',
        'src/Interface/Rest/Controller/CurrentSessionController.php::refresh()',
        'src/Interface/Rest/Controller/EmergencyContactController.php::destroy()',
        'src/Interface/Rest/Controller/EmergencyContactController.php::index()',
        'src/Interface/Rest/Controller/EmergencyContactController.php::store()',
        'src/Interface/Rest/Controller/EmergencyContactController.php::update()',
        'src/Interface/Rest/Controller/HealthController.php::__invoke()',
        'src/Interface/Rest/Controller/VersionsController.php::__invoke()',
        'src/Interface/Safety/Controller/IncidentController.php::show()',
        'src/Interface/Safety/Controller/IncidentController.php::store()',
    ];

    /**
     * The persistence surface an adapter may not touch.
     *
     * `BE-087` puts the ORM in a repository; these are the layers beneath and
     * beside it that would let an adapter read the store without one.
     *
     * @var list<string>
     */
    private const PERSISTENCE = [
        'ConnectionInterface',
        'ConnectionResolverInterface',
        'DatabaseManager',
        '\\DB::',
        'DB::table',
        'PDO',
    ];

    public function test_every_operation_invokes_exactly_one_application_service(): void
    {
        // API-002 ‡ / AADR-01 / BE-013.
        $offenders = [];

        foreach (self::operations() as $where => $invocations) {
            $expected = array_key_exists($where, self::WITHOUT_A_SERVICE) ? 0 : 1;

            if ($invocations !== $expected) {
                $offenders[] = $where.' → '.$invocations.' application service invocations';
            }
        }

        self::assertSame(
            [],
            $offenders,
            'API-002 ‡: an operation invokes exactly one application service. BE-041 puts one use case in one '
            .'service, and an adapter calling two would be composing a third in the interface layer. An '
            .'operation invoking none either belongs in WITHOUT_A_SERVICE with its authority, or is missing '
            .'the service that should realise it.',
        );
    }

    /**
     * `TC-024` ‡ — the rule reads **every** operation, which is the property it
     * lacked.
     *
     * A rule whose subject it derives itself cannot distinguish an empty offender
     * list from an empty subject. The old one asserted only that the subject was
     * non-empty, and it was: six of eleven.
     */
    public function test_every_operation_the_platform_exposes_is_read(): void
    {
        $read = array_keys(self::operations());

        sort($read);

        self::assertSame(
            self::OPERATIONS,
            $read,
            'API-002 ‡ is asserted of the operations this rule can see. An operation missing from this list is '
            .'one the rule never examined; an operation in the list and not in the platform is a rule asserting '
            .'something about nothing.',
        );
    }

    /**
     * An exemption that has outlived its reason is worse than no exemption, because
     * it reads as a decision.
     */
    public function test_each_exemption_is_still_the_operation_it_was_granted_for(): void
    {
        $operations = self::operations();

        foreach (self::WITHOUT_A_SERVICE as $where => $authority) {
            self::assertArrayHasKey($where, $operations, $where.' is exempt from API-002 ‡ and no longer exists.');
            self::assertSame(
                0,
                $operations[$where],
                $where.' is recorded as invoking no application service ("'.$authority.'") and now invokes one. '
                .'The exemption is what should go, not the assertion.',
            );
        }
    }

    public function test_no_adapter_reads_persistence_at_all(): void
    {
        // API-003 ‡. Broader than rule 3 on purpose: the ORM is one way to read
        // the store and the query layer is another, and this statement forbids
        // both.
        $offenders = [];

        foreach (self::interfaceFiles() as $relative => $contents) {
            foreach (self::PERSISTENCE as $reader) {
                if (self::namesInCode($contents, $reader)) {
                    $offenders[] = $relative.' → '.$reader;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'API-003 ‡: an operation reads persistence only through an application service.',
        );
    }

    /**
     * `SEC-037` ‡ — *"A token shall be carried in a request header and never in a
     * URI, a query parameter or a body field."*
     *
     * `SessionCarriage::tokenIn()` is the one door: it is the only thing that
     * turns a request value into a token, and `RequireSession` is its only
     * caller. So the statement reduces to something checkable — **every call site
     * reads a header** — and the check is future-proof in the way a rule naming
     * two files would not be: a third file resolving a token from a query string
     * fails this, wherever it is put.
     *
     * `SessionCarriageTest` asserts the behaviour at level 4; this asserts that
     * the behaviour has no second way in. `NFR-062` is why both are worth having:
     * a URI reaches a server log, a proxy and a referrer, and a query parameter
     * reaches all three.
     */
    public function test_a_session_token_is_resolved_from_a_header_and_from_nothing_else(): void
    {
        $offenders = [];
        $callSites = 0;

        foreach (self::interfaceFiles() as $relative => $contents) {
            $code = self::codeOf($contents);

            foreach (self::statementsCalling($code, 'SessionCarriage::tokenIn(') as $statement) {
                $callSites++;

                if (! str_contains($statement, 'headers->get')) {
                    $offenders[] = $relative.' → '.trim($statement);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'SEC-037 ‡: a token is read from a request header and from nothing else — never a URI, a query '
            .'parameter or a body field.',
        );

        // A rule with no call site proves nothing. RequireSession is the one.
        self::assertSame(1, $callSites, 'SEC-037 ‡: the token has exactly one resolution point.');
    }

    /**
     * `TC-041` / `TC-024` ‡ — both detectors are shown to fire, and shown not to
     * fire on the prose that describes them.
     */
    public function test_the_interface_detectors_recognise_what_their_rules_exist_for(): void
    {
        // API-003 ‡'s detector reads code, not comments — the correction rule 11
        // needed, applied from the start here.
        self::assertTrue(self::namesInCode('use Illuminate\Database\ConnectionInterface;', 'ConnectionInterface'));
        self::assertTrue(self::namesInCode('$rows = DB::table("op_users")->get();', 'DB::table'));

        self::assertFalse(
            self::namesInCode('// An adapter never names a ConnectionInterface — API-003 ‡.', 'ConnectionInterface'),
            'A file that writes about the prohibition is not a file that breaks it.',
        );

        self::assertFalse(self::namesInCode('use Cmp\Application\User\ReadEmergencyContacts;', 'PDO'));

        // API-002 ‡'s detector counts invocations on the collaborators the
        // constructor takes, whatever the method is called.
        self::assertSame(1, self::invocationsOf(['read'], '$result = $this->read->execute($command, $actor);'));
        self::assertSame(1, self::invocationsOf(['health'], '$health = $this->health->now();'));
        self::assertSame(2, self::invocationsOf(['read', 'amend'], '$this->read->execute($c); $this->amend->execute($d);'));

        // Two things asked of one service is still a use case composed in an
        // adapter, which the old predicate could not see either.
        self::assertSame(2, self::invocationsOf(['read'], '$a = $this->read->execute($c); $b = $this->read->count();'));

        // And what an operation does besides invoking a service is not an
        // invocation of one.
        self::assertSame(0, self::invocationsOf(['read'], 'return $this->envelope($data, false);'));
        self::assertSame(0, self::invocationsOf([], '$health = $this->health->now();'));

        // The property reader resolves a promoted type through the file's imports,
        // and leaves the envelope's collaborators out of it.
        self::assertSame(
            ['read'],
            self::applicationServicePropertiesIn(self::codeOf(<<<'PHP'
                <?php

                use Cmp\Application\Shared\Response\EvaluationTime;
                use Cmp\Application\User\ReadEmergencyContacts;

                final class Probe
                {
                    public function __construct(
                        private readonly ReadEmergencyContacts $read,
                        private readonly EvaluationTime $evaluatedAt,
                    ) {}
                }
                PHP)),
        );

        // A service with no actor is a service, and it is one because the list says
        // so rather than because of what it is called.
        self::assertSame(
            ['health'],
            self::applicationServicePropertiesIn(self::codeOf(<<<'PHP'
                <?php

                use Cmp\Application\Shared\Degradation\ReportPlatformHealth;

                final class Probe
                {
                    public function __construct(private readonly ReportPlatformHealth $health) {}
                }
                PHP)),
        );

        // SEC-037 ‡'s detector reads the statement a call sits in, so that the
        // source of the value is visible rather than assumed.
        self::assertSame(
            ['        $token = SessionCarriage::tokenIn($request->headers->get(X));'],
            self::statementsCalling(
                '        $token = SessionCarriage::tokenIn($request->headers->get(X));',
                'SessionCarriage::tokenIn(',
            ),
        );

        self::assertSame(
            ['$t = SessionCarriage::tokenIn($request->query("token"));'],
            self::statementsCalling('$t = SessionCarriage::tokenIn($request->query("token"));', 'SessionCarriage::tokenIn('),
        );

        self::assertSame([], self::statementsCalling('$x = somethingElse($request->headers->get(Y));', 'SessionCarriage::tokenIn('));
    }

    /**
     * Every statement in which a call appears, as written.
     *
     * A statement rather than a line, because the value a call is handed may sit
     * on the next one — and a detector that read one line would miss exactly the
     * formatting a developer reaches for when a line grows long.
     *
     * @return list<string>
     */
    private static function statementsCalling(string $code, string $call): array
    {
        $found = [];

        foreach (explode(';', $code) as $statement) {
            if (str_contains($statement, $call)) {
                $found[] = $statement.';';
            }
        }

        return $found;
    }

    /**
     * Every operation, and how many application service operations it invokes.
     *
     * A controller's public methods are its operations: the routes bind them, and
     * `API-002` ‡ is about what one operation does. A private helper is not an
     * operation and a constructor is not one either, which {@see ReadsMethods}
     * handles.
     *
     * @return array<string, int>
     */
    private static function operations(): array
    {
        $operations = [];

        foreach (self::interfaceFiles() as $relative => $contents) {
            if (! str_contains($relative, '/Controller/')) {
                continue;
            }

            $code = self::codeOf($contents);
            $services = self::applicationServicePropertiesIn($code);

            foreach (self::publicMethodsIn($code) as $name => $body) {
                $operations[$relative.'::'.$name.'()'] = self::invocationsOf($services, $body);
            }
        }

        return $operations;
    }

    /**
     * How many times a method calls into one of the given collaborators.
     *
     * Any call, not only `execute()`: `API-002` ‡ counts **operations of an
     * application service**, and asking one service for two things is composing a
     * use case just as much as asking two services for one thing each.
     *
     * @param  list<string>  $services  property names
     */
    private static function invocationsOf(array $services, string $body): int
    {
        $invocations = 0;

        foreach ($services as $property) {
            $invocations += preg_match_all('/\$this->'.preg_quote($property, '/').'->/', $body);
        }

        return $invocations;
    }

    /**
     * The constructor properties that hold an application service.
     *
     * A promoted property's declared type is resolved through the file's own
     * imports, and an import is a service when it extends {@see ApplicationService}
     * or is declared in {@see SERVICES_WITHOUT_AN_ACTOR}. `EvaluationTime` and
     * `ConfigurationVersion` live in `Cmp\Application` and are neither: they are
     * what `API-043` ‡ and `API-186` put in an envelope, so a rule that counted
     * every application-namespace collaborator would have found three invocations
     * in an operation that performs one.
     *
     * @return list<string>
     */
    private static function applicationServicePropertiesIn(string $code): array
    {
        preg_match_all('/^use\s+(Cmp\\\\Application\\\\[^;]+);/m', $code, $imports);

        $services = [];

        foreach ($imports[1] as $class) {
            $short = substr($class, (int) strrpos($class, '\\') + 1);

            if (array_key_exists($short, self::SERVICES_WITHOUT_AN_ACTOR)) {
                $services[] = $short;

                continue;
            }

            if (class_exists($class) && is_subclass_of($class, ApplicationService::class)) {
                $services[] = $short;
            }
        }

        $properties = [];

        preg_match_all(
            '/(?:private|protected|public)\s+readonly\s+\??(\w+)\s+\$(\w+)/',
            $code,
            $declared,
            PREG_SET_ORDER,
        );

        foreach ($declared as $property) {
            if (in_array($property[1], $services, true)) {
                $properties[] = $property[2];
            }
        }

        return $properties;
    }

    private static function namesInCode(string $contents, string $needle): bool
    {
        return str_contains(self::codeOf($contents), $needle);
    }

    /**
     * @return array<string, string>
     */
    private static function interfaceFiles(): array
    {
        $root = dirname(__DIR__, 2).'/';
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'src/Interface', RecursiveDirectoryIterator::SKIP_DOTS),
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
}
