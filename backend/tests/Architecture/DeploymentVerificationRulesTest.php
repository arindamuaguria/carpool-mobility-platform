<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Cmp\Infrastructure\Persistence\Schema\CheckConstraintEnforcementProbe;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `OPS-122` ‡ — *"Enforcement shall be verified at deployment by attempting a
 * violating write, not by reading a version string."*
 *
 * The probe does attempt one: it creates a table carrying a `CHECK`, inserts a
 * value the constraint forbids, and requires the server to refuse. Nothing
 * observed that.
 *
 * ## Why the statement is phrased as a prohibition
 *
 * `OPS-121` requires a MySQL version that enforces `CHECK`; `DB-217` and
 * `TC-047` want that **verified**. Reading `VERSION()` looks like verification
 * and is not: a version string says what the vendor shipped, not what this
 * server does with this schema under this configuration. A server can report
 * 8.4 and still have been started in a mode that ignores a constraint, and the
 * failure then appears as accepted data rather than as a refused deployment.
 *
 * So replacing the probe with a version comparison is the exact regression this
 * statement forbids — and it is the change somebody makes when the probe's
 * `CREATE TABLE` is inconvenient in a locked-down environment. It would have
 * passed every test in this suite.
 *
 * `TC-024` ‡: the detector reads code rather than prose, and both directions are
 * validated.
 */
final class DeploymentVerificationRulesTest extends TestCase
{
    use ReadsMethods;

    /**
     * Ways of asking the server what it is instead of what it does.
     *
     * @var list<string>
     */
    private const VERSION_READS = [
        'VERSION()',
        '@@version',
        'innodb_version',
        'getServerVersion',
        'SELECT @@',
    ];

    public function test_the_probe_attempts_a_violating_write(): void
    {
        // OPS-122 ‡'s positive half. A probe that stopped inserting would stop
        // verifying anything.
        $code = self::probeCode();

        self::assertStringContainsString('INSERT INTO', $code, 'OPS-122 ‡: enforcement is verified by a write.');
        self::assertStringContainsString('CHECK', $code, 'The write must violate a constraint to prove anything.');
    }

    public function test_the_probe_reads_no_version_string(): void
    {
        // OPS-122 ‡'s prohibition, which is the half worth having: a version
        // string says what the vendor shipped, not what this server does.
        $code = self::probeCode();
        $offenders = [];

        foreach (self::VERSION_READS as $read) {
            if (str_contains($code, $read)) {
                $offenders[] = $read;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'OPS-122 ‡: enforcement is verified by attempting a violating write, not by reading a version string.',
        );
    }

    public function test_the_probe_leaves_nothing_behind(): void
    {
        // Not OPS-122 ‡'s, but its consequence: a probe that verifies by writing
        // must not leave what it wrote. DB-215's migration account runs this, and
        // a residual table would be schema nobody declared.
        self::assertStringContainsString('DROP TABLE IF EXISTS', self::probeCode());
    }

    /**
     * `TC-041` / `TC-024` ‡ — the detector fires on the forbidden shape and not
     * on the prose that describes it.
     */
    public function test_the_version_read_detector_recognises_what_it_forbids(): void
    {
        self::assertTrue(self::readsAVersion("\$row = \$this->connection->select('SELECT VERSION()');"));
        self::assertTrue(self::readsAVersion('$v = $pdo->getServerVersion();'));
        self::assertTrue(self::readsAVersion("if (\$this->connection->select('SELECT @@version')) {"));

        self::assertFalse(
            self::readsAVersion('// OPS-122 ‡ forbids reading VERSION() in place of a write.'),
            'A file that writes about the prohibition is not a file that breaks it.',
        );

        self::assertFalse(self::readsAVersion("\$this->connection->insert('INSERT INTO probe (value) VALUES (?)', [-1]);"));
    }

    private static function readsAVersion(string $snippet): bool
    {
        $code = self::codeOf($snippet);

        foreach (self::VERSION_READS as $read) {
            if (str_contains($code, $read)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The probe's source, comments removed.
     *
     * Read through the tokeniser for the reason `TC-037` ‡ rule 11 was corrected:
     * this class's own docblock names `VERSION()` in order to forbid it, and a
     * detector reading prose would flag the explanation and be "fixed" by
     * deleting it.
     */
    private static function probeCode(): string
    {
        $file = (new ReflectionClass(CheckConstraintEnforcementProbe::class))->getFileName();

        self::assertIsString($file);

        $contents = file_get_contents($file);

        self::assertIsString($contents);

        return self::codeOf($contents);
    }
}
