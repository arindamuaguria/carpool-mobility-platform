<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\UnverifiableRegister;

/**
 * CMP-DOC-18 §16 — test data.
 *
 * Fourteen statements, seven of them integrity-critical, and all of them about
 * the same worry: a test suite is the one place where somebody reaches for real
 * data because it is easier than making some up. `TC-206` states the rule plainly
 * — *"test data shall be generated, not copied from production"* — and everything
 * else here is a way of noticing when it has not been.
 *
 * ## What is enforced here, and what is not
 *
 * Ten of the fourteen are checkable from the tree and are checked below. The
 * other four are recorded rather than enforced, because enforcing them would
 * mean asserting something this repository cannot see:
 *
 * | Statement | Why it is not enforced here |
 * |---|---|
 * | `TC-207` ‡ | The realistic volume is **unset** — `GAP-016`, launch scale unstated. `TC-199` forbids inventing a figure, so there is no number to check against. |
 * | `TC-210` | Boundary conditions are derived per obligation, and 48 of the 99 are blocked. What a blocked obligation's boundaries are is not knowable. |
 * | `TC-212` | Test environment provisioning is CMP-DOC-19's, and `BAD-DEP-009` has selected no hosting. |
 * | `TC-213` ‡ | Retention rules for personal data are `BAD-DEC-021`, and eight of nine periods are unset. There is no rule yet to apply equally. |
 *
 * Each is in {@see UnverifiableRegister} territory rather than a gap: they are
 * unenforceable **because a decision is missing**, and `TADR-12` says to record
 * that rather than pad it.
 */
final class TestDataRulesTest extends TestCase
{
    /**
     * `TC-203` ‡ — a phone number in the suite must be impossible to dial.
     *
     * `BAD-RULE-043` makes the verified phone number the account's one mandatory
     * identifying detail, so it is the only personal datum the platform holds and
     * therefore the only one a test could copy from production. Every literal is
     * required to be `+91` followed by a run of zeros — a shape no subscriber has
     * and no generator would produce by accident.
     */
    private const SYNTHETIC_NUMBER = '/^\+910{6,}\d{1,4}$/';

    /**
     * The one file each detector below does not scan: the file that declares it.
     *
     * `TC-024` ‡ requires a false positive to be **fixed, never disabled**, and
     * this is a fix rather than a suppression — a rule's own statement of what it
     * forbids is not an instance of the forbidden thing. The validation fixtures
     * in {@see test_the_synthetic_number_rule_tells_a_generated_number_from_a_real_one()}
     * exist precisely so that somebody can see the rule reject a plausible
     * number, and a detector that flagged them would make its own validation
     * impossible to write.
     *
     * Named as a single site with the reason recorded, which is the pattern
     * `StructuralRulesTest` already uses for rule 9's two declared statement
     * sites. **One file, not a directory** — an exclusion broad enough to hide a
     * real finding is a suppression whatever it is called.
     */
    private const DECLARED_SITE = 'tests/Architecture/TestDataRulesTest.php';

    public function test_every_phone_number_in_the_suite_is_synthetic(): void
    {
        // TC-203 ‡ / SEC-170 / NFR-065: no production personal data.
        $offenders = [];

        foreach (self::sourceFiles() as $path => $contents) {
            preg_match_all('/\+91[0-9]+/', $contents, $matches);

            foreach ($matches[0] as $number) {
                if (preg_match(self::SYNTHETIC_NUMBER, $number) !== 1) {
                    $offenders[] = $path.' → '.$number;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'TC-203 ‡: a phone number in the suite could belong to somebody. TC-206: generated, not copied.',
        );
    }

    public function test_the_synthetic_number_rule_tells_a_generated_number_from_a_real_one(): void
    {
        // TC-024 ‡: a detector nobody validated is a detector nobody can trust
        // when it fires. The numbers the suite actually uses, and shapes a real
        // one would take.
        foreach (['+910000000001', '+910000000053', '+910000000156'] as $synthetic) {
            self::assertMatchesRegularExpression(self::SYNTHETIC_NUMBER, $synthetic);
        }

        foreach (['+919876543210', '+911234567890', '+910123456789'] as $plausible) {
            self::assertDoesNotMatchRegularExpression(
                self::SYNTHETIC_NUMBER,
                $plausible,
                $plausible.' looks dialable and the rule would let it through.',
            );
        }
    }

    public function test_no_test_holds_a_payment_instrument_credential(): void
    {
        // TC-205 ‡: "test data shall contain no payment instrument credential,
        // because none can exist anywhere." SADR-10 and DB-037 make it absolute;
        // PaymentCredentialAbsenceTest covers the schema, and this covers the
        // fixtures — a card number in a test is a card number in the repository.
        //
        // Thirteen to nineteen digits is the card range. A hash is hexadecimal
        // and an identifier is not a bare run of decimal digits, so this catches
        // what it is looking for and little else.
        $offenders = [];

        foreach (self::sourceFiles() as $path => $contents) {
            if (preg_match_all('/(?<![0-9a-zA-Z_])[0-9]{13,19}(?![0-9])/', $contents, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $run) {
                $offenders[] = $path.' → '.$run;
            }
        }

        self::assertSame([], $offenders, 'TC-205 ‡ / SADR-10: no payment instrument surface anywhere.');
    }

    public function test_no_email_address_appears_anywhere(): void
    {
        // TC-203 ‡ again, from the other side. BAD-RULE-043 fixes the mandatory
        // identifying detail at the verified phone number **and nothing else**,
        // and the CMP-IMP-051 migration records that no email column may be added
        // on the ground that it may be useful. An address in a test would be
        // personal data about somebody the platform has no field for.
        $offenders = [];

        foreach (self::sourceFiles() as $path => $contents) {
            preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $contents, $matches);

            foreach ($matches[0] as $address) {
                $offenders[] = $path.' → '.$address;
            }
        }

        self::assertSame([], $offenders, 'TC-203 ‡ / BAD-RULE-043: the platform collects no email address.');
    }

    public function test_no_seeder_writes_a_policy_value(): void
    {
        // TC-208 / TC-209 ‡: "no seed value shall stand in for an undecided
        // business decision — no default fare, no default retention period, no
        // default role."
        //
        // The structural form of that rule is this: a seeder must not write a
        // policy value at all. BADR-12 applies one by an **operator action** that
        // BE-173 evidences, and a seeder is not an operator — a seeded
        // session.lifetime would bypass ChangePolicyValue and its evidential
        // record, and would look afterwards exactly like a decision somebody took.
        $offenders = [];

        foreach (self::filesUnder(dirname(__DIR__, 2).'/database/seeders') as $path => $contents) {
            foreach ([
                'PolicyStore', 'ChangePolicyValue', 'PolicyKey', 'cfg_policy',
                'session.lifetime', 'session.concurrent_limit', 'authentication.hash',
            ] as $forbidden) {
                if (str_contains($contents, $forbidden)) {
                    $offenders[] = $path.' → '.$forbidden;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'TC-209 ‡: a seeder sets a policy value. BADR-12 requires an operator action, and BE-173 '
            .'evidences it — a seed does neither.',
        );
    }

    public function test_no_seeder_or_factory_exists_for_a_withheld_capability(): void
    {
        // TC-216: "test data for a withheld capability shall not exist, because
        // no such capability exists." TADR-13 and ADM-187/ADM-191 say the same of
        // the capability itself; a factory for one would be the same mistake
        // wearing a different hat.
        $offenders = [];
        $root = dirname(__DIR__, 2).'/database';

        foreach (self::filesUnder($root) as $path => $contents) {
            $term = UnverifiableRegister::withheldTermIn($path.' '.$contents);

            if ($term !== null) {
                $offenders[] = $path.' → '.$term;
            }
        }

        self::assertSame([], $offenders, 'TC-216: test data exists for a withheld capability.');
    }

    public function test_no_test_depends_on_data_another_test_created(): void
    {
        // TC-214: "a test shall create the data it needs and shall not depend on
        // data another test created." The mechanism by which one test depends on
        // another is PHPUnit's own, and its absence is checkable — a suite with
        // no declared dependency has no ordered pairs to go wrong.
        //
        // TC-150 is cited as the source, and the connection is the point: an
        // ordering dependency is the most common way a suite becomes flaky, and
        // TC-150 forbids fixing flakiness by retrying.
        $offenders = [];

        foreach (self::sourceFiles() as $path => $contents) {
            foreach (['@depends', '#[Depends', '#[DependsExternal', '#[DependsUsing'] as $mechanism) {
                if (str_contains($contents, $mechanism)) {
                    $offenders[] = $path.' → '.$mechanism;
                }
            }
        }

        self::assertSame([], $offenders, 'TC-214: a test depends on another test having run.');
    }

    public function test_the_concurrency_test_creates_its_contended_data_deliberately(): void
    {
        // TC-215 ‡: "a concurrency test shall create contended data deliberately,
        // not rely on collision by chance." ConcurrentIdempotencyClaimTest races
        // a key it names, at an instant it sets — nothing about it waits for two
        // things to collide on their own.
        $path = dirname(__DIR__).'/Integration/Persistence/ConcurrentIdempotencyClaimTest.php';

        self::assertFileExists($path, 'TC-215 ‡ needs a concurrency test to be about.');

        $contents = file_get_contents($path);

        self::assertIsString($contents);

        // The barrier and the named key are what make the contention deliberate.
        self::assertStringContainsString('$startAt = microtime(true)', $contents);
        self::assertStringContainsString('key-concurrent-1', $contents);
    }

    public function test_the_env_example_carries_no_secret_value(): void
    {
        // TC-204 ‡: "no production secret shall exist in a test environment."
        // SADR-14 and OPS-098 keep a secret out of the repository entirely, and
        // .env.example is the file that most invites one — it names every secret
        // the platform needs and is committed.
        //
        // So every secret-shaped key in it must be declared and **empty**.
        $path = dirname(__DIR__, 2).'/.env.example';

        self::assertFileExists($path);

        $contents = file_get_contents($path);

        self::assertIsString($contents);

        // Spaces and tabs around the `=`, never `\s` — which includes the
        // newline, so an empty `APP_KEY=` captured the whole of the next line and
        // reported APP_DEBUG's value as APP_KEY's secret. TC-024 ‡: the detector
        // was wrong and was fixed.
        preg_match_all(
            '/^([A-Z0-9_]*(?:PASSWORD|SECRET|KEY|TOKEN))[ \t]*=[ \t]*(.*)$/m',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        self::assertNotSame([], $matches, 'The example names no secret at all, so this check proved nothing.');

        foreach ($matches as [, $name, $value]) {
            self::assertSame(
                '',
                trim($value),
                'TC-204 ‡ / SADR-14: .env.example carries a value for '.$name.'. A secret is injected at '
                .'deploy time and never appears in the repository.',
            );
        }
    }

    public function test_the_real_env_is_not_committed(): void
    {
        // The other half of TC-204 ‡. .env holds the working credentials for this
        // machine's MySQL; OPS-098 keeps it out of the artefact and the
        // repository, and .gitignore is what does it.
        $ignore = file_get_contents(dirname(__DIR__, 2).'/.gitignore');

        self::assertIsString($ignore);
        self::assertMatchesRegularExpression('/^\.env$/m', $ignore, 'SADR-14: .env must be ignored.');
    }

    /**
     * Every PHP file in `tests/` and `src/`.
     *
     * Both, because `TC-203` ‡ is about the repository rather than about the
     * suite: personal data hard-coded in a fixture and personal data hard-coded
     * in a service are the same disclosure.
     *
     * @return array<string, string>
     */
    private static function sourceFiles(): array
    {
        $files = [];
        $root = dirname(__DIR__, 2);

        foreach ([$root.'/tests', $root.'/src'] as $tree) {
            $files += self::filesUnder($tree);
        }

        // The one recorded exception. See DECLARED_SITE.
        unset($files[self::DECLARED_SITE]);

        self::assertNotSame([], $files, 'The scan found nothing, so it proved nothing.');

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private static function filesUnder(string $tree): array
    {
        if (! is_dir($tree)) {
            return [];
        }

        $files = [];
        $root = str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 2)).'/';

        /** @var iterable<SplFileInfo> $found */
        $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree));

        foreach ($found as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (is_string($contents)) {
                // Forward slashes and relative: a path that differed only by
                // separator would make DECLARED_SITE silently fail to match,
                // and it did before this line said so.
                $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

                $files[str_replace($root, '', $path)] = $contents;
            }
        }

        return $files;
    }
}
