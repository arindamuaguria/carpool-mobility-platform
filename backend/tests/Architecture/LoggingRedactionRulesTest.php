<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\ConductSet;
use Tests\ObligationRegister;

/**
 * `SEC-208` ‡ and `SEC-210` — nothing forbidden reaches a logger, by construction.
 *
 * `SEC-208` ‡: *"no log shall contain a credential, a session token, a payment
 * instrument detail, a precise location or a contact detail."* `SEC-210` fixes the
 * mechanism and is the statement this file enforces: *"exclusion shall be by
 * construction — **the value is not passed to the logger** — rather than by
 * redaction after the fact."*
 *
 * That distinction decides the whole design. A redaction layer is what a platform
 * builds once it has already handed the value over and hopes to catch it leaving;
 * `SEC-144` and `SADR-09` take the same position about a query, and `BE-201` ‡
 * about an evidential record. So there is no redaction layer here. There is a
 * check at the **call site**, and a call site that hands over a forbidden value
 * fails the build.
 *
 * ## Why this is not a fourteenth structural rule
 *
 * `TC-037` ‡ declares **thirteen** and `StructuralRulesTest` asserts exactly
 * thirteen. A fourteenth would make that register wrong, and `TC-006`'s reasoning
 * about the obligation register applies here too — a closed register that grows
 * quietly stops being one. This is `SEC-210`'s rule, in `SEC-210`'s own file.
 */
final class LoggingRedactionRulesTest extends TestCase
{
    /**
     * The methods a logger exposes. A call to one of these is a log line.
     *
     * @var list<string>
     */
    private const LOG_METHODS = [
        'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log',
    ];

    /**
     * The one file this rule does not scan: the one that declares it.
     *
     * The same recorded exception `TestDataRulesTest` uses, for the same reason —
     * a rule's own statement of what it forbids is not an instance of it, and the
     * validation below has to be able to name a forbidden key to prove the
     * detector works. One file, named, with the reason recorded.
     */
    private const DECLARED_SITE = 'tests/Architecture/LoggingRedactionRulesTest.php';

    public function test_no_log_call_names_a_forbidden_value(): void
    {
        // SEC-208 ‡ / SEC-210. The check is on the **context array key** at the
        // call site, because that is the name the value is handed over under —
        // and a value handed over under an honest name is the case this can
        // actually catch.
        $offenders = [];

        foreach (self::logCallSites() as $site => $call) {
            $forbidden = self::forbiddenKeyIn($call);

            if ($forbidden !== null) {
                $offenders[] = $site.' → '.$forbidden;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'SEC-210: the value is not passed to the logger. A log call hands over something SEC-208 ‡ '
            .'forbids, and redaction afterwards is not the remedy.',
        );
    }

    public function test_the_scan_finds_the_log_calls_that_exist(): void
    {
        // A detector that found nothing would pass for the wrong reason. Two
        // sites exist today — the authorisation refusal recorder and the
        // reconciliation raiser — and this fails if the scan stops seeing them.
        $sites = self::logCallSites();

        self::assertNotSame([], $sites, 'The scan found no log call at all, so it proved nothing.');

        $files = array_unique(array_map(
            static fn (string $site): string => substr($site, 0, (int) strpos($site, ':')),
            array_keys($sites),
        ));

        self::assertContains('src/Infrastructure/Authorisation/EvidentialAuthorisationRefusals.php', $files);
        self::assertContains('src/Infrastructure/Evidential/LoggingReconciliationRaises.php', $files);
    }

    public function test_the_detector_tells_a_forbidden_key_from_an_innocent_one(): void
    {
        // TC-024 ‡: fixed, never disabled — which presumes somebody can tell one
        // from the other when it fires.
        //
        // Forbidden, and each for a different statement.
        self::assertSame('token', self::forbiddenKeyIn("\$this->logger->info('x', ['token' => \$t]);"));
        self::assertSame('phone_number', self::forbiddenKeyIn("\$log->warning('y', ['phone_number' => \$n]);"));
        self::assertSame('latitude', self::forbiddenKeyIn("\$log->debug('z', ['latitude' => \$lat]);"));
        self::assertSame('material', self::forbiddenKeyIn("\$log->error('e', ['material' => \$m]);"));

        // Innocent, and every one of them appears in the platform today or would
        // be reasonable to add. `operation`, `actor` and `cause` are the three
        // EvidentialAuthorisationRefusals actually logs.
        foreach ([
            "\$this->logger->warning('a', ['operation' => \$o, 'actor' => \$a, 'cause' => \$c]);",
            "\$log->critical('b', ['evidential' => false]);",
            "\$log->info('c', ['correlation' => \$id, 'outcome' => 'refused']);",
        ] as $innocent) {
            self::assertNull(self::forbiddenKeyIn($innocent), $innocent.' is innocent and was flagged.');
        }

        // The near-misses that must not fire. A key ending in a forbidden word is
        // not that word — `evidential` is not `denial`, and `position_held` would
        // be, which is why the match is on the whole key.
        self::assertNull(self::forbiddenKeyIn("\$log->info('d', ['evidential' => true]);"));
        self::assertNull(self::forbiddenKeyIn("\$log->info('e', ['operation' => 'sessions.establish']);"));
    }

    public function test_the_conduct_set_is_the_eight_sec_206_names(): void
    {
        // SEC-206 ‡ names them and closes the set. A ninth would be a conduct
        // nobody agreed to record; an eighth missing would be one nobody records.
        self::assertSame(
            [
                'refused_authorisation', 'assertion_attempt', 'rate_limit_breach', 'authentication_failure',
                'session_anomaly', 'operator_override', 'policy_change', 'chain_divergence',
            ],
            array_keys(ConductSet::all()),
        );
    }

    public function test_a_wired_conduct_names_a_writer_and_a_test_that_both_exist(): void
    {
        // The same discipline the other registers use: a claim that cannot be
        // checked is worth nothing, and a status maintained by hand drifts.
        foreach (ConductSet::all() as $conduct => $entry) {
            if ($entry['status'] !== ObligationRegister::ENFORCED) {
                self::assertNull($entry['writtenBy'], $conduct.' is not wired and names a writer.');
                self::assertNull($entry['provenBy'], $conduct.' is not wired and names a test.');
                self::assertNotSame('', trim($entry['note']), $conduct.' does not say why.');

                continue;
            }

            self::assertIsString($entry['writtenBy'], $conduct.' claims to be wired and names no writer.');
            self::assertIsString($entry['provenBy'], $conduct.' claims to be wired and names no test.');
            self::assertTrue(
                class_exists($entry['writtenBy']) || interface_exists($entry['writtenBy']),
                $conduct.' names '.$entry['writtenBy'].', which does not exist.',
            );
            self::assertTrue(class_exists($entry['provenBy']), $conduct.' names '.$entry['provenBy'].', which is gone.');
        }
    }

    public function test_operational_logging_never_stands_in_for_the_evidential_record(): void
    {
        // SEC-205 ‡ / BE-202. The two log sites that exist are checked directly:
        // the refusal recorder writes evidence **before** it logs, and says so in
        // the line itself with `evidential => false`.
        $contents = file_get_contents(
            dirname(__DIR__, 2).'/src/Infrastructure/Authorisation/EvidentialAuthorisationRefusals.php',
        );

        self::assertIsString($contents);

        $evidenceAt = strpos($contents, '$this->evidence->record(');
        $logAt = strpos($contents, '$this->logger->warning(');

        self::assertIsInt($evidenceAt);
        self::assertIsInt($logAt);
        self::assertLessThan(
            $logAt,
            $evidenceAt,
            'BE-202: the evidential record is written first, so a log line means it exists.',
        );

        self::assertStringContainsString("'evidential' => false", $contents);
    }

    /**
     * The forbidden context key a log call hands a value under, or null.
     *
     * Matched as a **whole key** in a `'key' => …` pair, so that `evidential` is
     * not read as a contact detail and `operation` is not read as a position.
     */
    private static function forbiddenKeyIn(string $call): ?string
    {
        foreach (ConductSet::forbiddenInALogLine() as $forbidden) {
            if (preg_match("/'".preg_quote($forbidden, '/')."'\s*=>/i", $call) === 1) {
                return $forbidden;
            }
        }

        return null;
    }

    /**
     * Every logger call in `src/`, by file and line.
     *
     * A call is `->method(` on one of {@see LOG_METHODS}, taken with the rest of
     * its statement so that a context array on the following lines is included.
     *
     * @return array<string, string>
     */
    private static function logCallSites(): array
    {
        $sites = [];
        $root = str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 2)).'/';

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'src'));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace($root, '', str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname()));

            if ($path === self::DECLARED_SITE) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents)) {
                continue;
            }

            $lines = explode("\n", $contents);

            foreach ($lines as $n => $line) {
                foreach (self::LOG_METHODS as $method) {
                    if (preg_match('/->'.$method.'\s*\(/', $line) !== 1) {
                        continue;
                    }

                    // A comment mentioning a call is not a call.
                    if (preg_match('/^\s*(\*|\/\/)/', $line) === 1) {
                        continue;
                    }

                    // The statement, to its closing `);` — a context array is
                    // usually on the lines below the method name.
                    $statement = implode("\n", array_slice($lines, $n, 20));
                    $end = strpos($statement, ');');

                    $sites[$path.':'.($n + 1)] = $end === false ? $statement : substr($statement, 0, $end + 2);

                    break;
                }
            }
        }

        return $sites;
    }
}
