<?php

declare(strict_types=1);

namespace Tests\Architecture;

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
 * - **`API-003` ‡**: *"No operation shall read persistence other than through an
 *   application service."* `StructuralRulesTest` rule 3 already keeps the **ORM**
 *   out of this layer, and that is narrower than the statement: a controller
 *   reaching the query layer directly carries no ORM type and would pass it.
 */
final class InterfaceStructureRulesTest extends TestCase
{
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

        foreach (self::controllerMethods() as $where => $body) {
            $invocations = preg_match_all('/->execute\(/', $body);

            if ($invocations !== 1) {
                $offenders[] = $where.' → '.$invocations.' application service invocations';
            }
        }

        self::assertSame(
            [],
            $offenders,
            'API-002 ‡: an operation invokes exactly one application service. BE-041 puts one use case in one '
            .'service, and an adapter calling two would be composing a third in the interface layer.',
        );

        // A rule that examined nothing proves nothing. Nine operations exist.
        self::assertGreaterThan(0, count(self::controllerMethods()));
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

        // API-002 ‡'s detector counts invocations rather than recognising a name.
        self::assertSame(1, preg_match_all('/->execute\(/', '$this->read->execute($command, $actor);'));
        self::assertSame(2, preg_match_all('/->execute\(/', '$a->execute($x); $b->execute($y);'));
        self::assertSame(0, preg_match_all('/->execute\(/', 'return $this->envelope($data, false);'));

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
     * Every public method of every controller, keyed by where it is.
     *
     * A controller's public methods are its operations: the routes bind them, and
     * `API-002` ‡ is about what one operation does.
     *
     * @return array<string, string>
     */
    private static function controllerMethods(): array
    {
        $methods = [];

        foreach (self::interfaceFiles() as $relative => $contents) {
            if (! str_contains($relative, '/Controller/')) {
                continue;
            }

            $code = self::codeOf($contents);

            preg_match_all(
                '/public function (\w+)\([^)]*\)[^{]*\{(.*?)\n    \}/s',
                $code,
                $matches,
                PREG_SET_ORDER,
            );

            foreach ($matches as $match) {
                if ($match[1] === '__construct') {
                    continue;
                }

                $methods[$relative.'::'.$match[1].'()'] = $match[2];
            }
        }

        return $methods;
    }

    private static function namesInCode(string $contents, string $needle): bool
    {
        return str_contains(self::codeOf($contents), $needle);
    }

    /**
     * A file's PHP with every comment removed.
     */
    private static function codeOf(string $contents): string
    {
        if (! str_contains($contents, '<?php')) {
            $contents = '<?php '.$contents;
        }

        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
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
