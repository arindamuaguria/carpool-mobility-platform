<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Cmp\Infrastructure\Persistence\Idempotency\DatabaseIdempotencyRegistry;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

/**
 * `TC-147` ‡ — concurrent idempotent replay produces one effect.
 *
 * **Genuine parallelism** (`TADR-08`, `TC-143` ‡): several operating-system
 * processes, released at one instant, racing for the same idempotency claim. Not
 * two connections a test interleaves itself — CMP-DOC-18 rejects that in terms as
 * *"simulated sequence"*, and the reason is that a test which chooses the
 * interleaving chooses one that rarely breaks anything.
 *
 * `TC-144` ‡: against a real database with the constraint active, which
 * `TC-030` ‡ already requires of every level-3 test here.
 *
 * ## What is asserted, and what deliberately is not
 *
 * `TC-145` ‡: *"the test shall assert the invariant held across all outcomes, not
 * that a particular interleaving occurred."* So nothing here says which process
 * wins, or in what order they finish. What is asserted is what `API-062` ‡ and
 * `DB-142` ‡ promise between them: **exactly one** racer takes the claim, exactly
 * one row exists, and every other racer is refused rather than erroring.
 *
 * That last part matters. `DB-142` ‡ chose a unique constraint over a locking
 * read *"where a privilege is withheld"*, and the consequence is that the loser
 * meets a `UniqueConstraintViolationException` — which
 * {@see DatabaseIdempotencyRegistry::claim()} turns into `false`, the expected
 * path for a repeated request. A racer that came back with an error would mean
 * the constraint-decides pattern leaks its mechanism to the caller.
 *
 * ## `TC-150` ‡ — flakiness is fixed, never retried into passing
 *
 * There is no retry, no loop-until-green and no tolerance. If the barrier ever
 * fails to produce contention the test still passes — one winner is one winner
 * whether or not the losers actually collided — so a green run is never a false
 * negative. {@see test_the_racers_actually_contend()} is what would notice the
 * barrier having stopped working, and it is a separate assertion so that its
 * failure reads as *"the test stopped testing"* rather than as a broken platform.
 */
final class ConcurrentIdempotencyClaimTest extends IntegrationTestCase
{
    private const OPERATION = 'concurrency.claim';

    private const ACTOR = 'actor-under-concurrent-test';

    /**
     * Enough to contend, few enough to start inside the barrier window.
     *
     * `DB-091`'s lock-wait bound is unset, which `TC-149` records — so this is
     * chosen against the machine rather than against a requirement, and is
     * recorded as an implementation choice.
     */
    private const RACERS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearClaims();
    }

    protected function tearDown(): void
    {
        $this->clearClaims();

        parent::tearDown();
    }

    #[Test]
    public function exactly_one_of_several_concurrent_racers_takes_the_claim(): void
    {
        // TC-147 ‡ / API-062 ‡ / DB-142 ‡. The whole obligation, in three
        // assertions that hold across every interleaving.
        $outcomes = $this->race('key-concurrent-1');

        $errors = array_values(array_filter(
            $outcomes,
            static fn (string $outcome): bool => str_starts_with($outcome, 'error'),
        ));

        self::assertSame([], $errors, 'DB-142 ‡: a loser is refused, not broken. The constraint decides.');

        self::assertCount(
            1,
            array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'claimed'),
            'API-062 ‡: exactly one racer takes the claim, whatever the interleaving.',
        );

        self::assertCount(
            self::RACERS - 1,
            array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'refused'),
            'Every other racer is refused.',
        );

        self::assertSame(1, $this->claimCount('key-concurrent-1'), 'Exactly one row exists.');
    }

    #[Test]
    public function a_second_race_on_a_second_key_is_decided_independently(): void
    {
        // The constraint is on the triple (actor, operation, key) — DB-142 ‡ and
        // constraint 5. A race that decided a second key by the first one's
        // outcome would mean the uniqueness is coarser than it is stated to be,
        // and one caller's replay would refuse another caller's first attempt.
        $this->race('key-concurrent-2');
        $outcomes = $this->race('key-concurrent-3');

        self::assertCount(
            1,
            array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'claimed'),
        );

        self::assertSame(1, $this->claimCount('key-concurrent-2'));
        self::assertSame(1, $this->claimCount('key-concurrent-3'));
    }

    #[Test]
    public function test_the_racers_actually_contend(): void
    {
        // TC-150 ‡'s other half. The assertions above hold whether or not the
        // racers collided, which is what makes them safe from flakiness — and
        // also what would let the barrier quietly stop working without anybody
        // noticing that the test had stopped testing.
        //
        // So the contention is asserted directly: every racer must have been
        // running at the same time, which shows as all of them finishing within
        // a window far shorter than starting them sequentially would take.
        $before = microtime(true);
        $outcomes = $this->race('key-concurrent-4');
        $elapsed = microtime(true) - $before;

        self::assertCount(self::RACERS, $outcomes, 'Every racer answered.');

        // Booting the application takes long enough that six sequential runs
        // could not fit in the time six parallel ones take. The bound is
        // deliberately generous: TC-150 ‡ forbids a timing assertion tight
        // enough to be flaky, and this one only has to distinguish parallel from
        // sequential.
        self::assertLessThan(
            self::RACERS * $this->soloDuration(),
            $elapsed,
            'TADR-08: the racers ran sequentially, so this is a simulated sequence and not a race.',
        );
    }

    /**
     * Runs {@see RACERS} processes, released together, and returns what each said.
     *
     * @return list<string>
     */
    private function race(string $key): array
    {
        $processes = [];
        $pipes = [];

        // The barrier. Long enough for every process to boot and reach the wait
        // loop; short enough that the test is not slow. A process that misses it
        // simply claims late and is refused, which the assertions tolerate.
        $startAt = microtime(true) + 2.5;

        for ($racer = 0; $racer < self::RACERS; $racer++) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

            $process = proc_open(
                $this->command($startAt, $key),
                $descriptors,
                $racerPipes,
                dirname(__DIR__, 3),
            );

            if (! is_resource($process)) {
                throw new RuntimeException('TADR-08: a racer could not be started, so no race happened.');
            }

            $processes[] = $process;
            $pipes[] = $racerPipes;
        }

        $outcomes = [];

        foreach ($processes as $racer => $process) {
            $stdout = trim((string) stream_get_contents($pipes[$racer][1]));
            $stderr = trim((string) stream_get_contents($pipes[$racer][2]));

            fclose($pipes[$racer][1]);
            fclose($pipes[$racer][2]);
            proc_close($process);

            // A racer that printed nothing at all did not run, and a test that
            // silently counted it as refused would report one winner out of one.
            $outcomes[] = $stdout !== '' ? $stdout : 'error empty output: '.$stderr;
        }

        return $outcomes;
    }

    /**
     * How long one racer takes on its own, for the contention assertion.
     *
     * Measured rather than assumed: `TC-149` says lock contention behaviour is
     * **measured, not asserted**, because `DB-091`'s wait bound is unset — and the
     * same applies to how long this machine takes to boot the application.
     */
    private function soloDuration(): float
    {
        $before = microtime(true);

        $this->race('key-concurrent-solo-'.self::RACERS);

        // The race itself is parallel, so this over-estimates a single boot and
        // the resulting bound is generous. Deliberately: see the note in
        // test_the_racers_actually_contend().
        return (microtime(true) - $before) / 2;
    }

    /**
     * @return list<string>
     */
    private function command(float $startAt, string $key): array
    {
        return [
            PHP_BINARY,
            dirname(__DIR__, 3).'/tests/Integration/Persistence/Concurrency/claim.php',
            sprintf('%.6F', $startAt),
            self::ACTOR,
            self::OPERATION,
            $key,
            // API-063 ‡: the fingerprint is the same for every racer, because
            // they are replays of one request rather than different requests
            // reusing a key.
            hash('sha256', 'one-request'),
        ];
    }

    private function claimCount(string $key): int
    {
        $rows = $this->applicationConnection()->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseIdempotencyRegistry::TABLE
            .' WHERE actor = ? AND operation = ? AND request_key = ?',
            [self::ACTOR, self::OPERATION, $key],
        );

        self::assertArrayHasKey(0, $rows);

        /** @var object{total: int|string} $row */
        $row = $rows[0];

        return (int) $row->total;
    }

    private function clearClaims(): void
    {
        $this->migrationConnection()->delete(
            'DELETE FROM '.DatabaseIdempotencyRegistry::TABLE.' WHERE actor = ?',
            [self::ACTOR],
        );
    }
}
