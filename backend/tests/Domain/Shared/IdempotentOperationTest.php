<?php

declare(strict_types=1);

namespace Tests\Domain\Shared;

use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Idempotency\IdempotentOperation;
use Cmp\Application\Shared\Idempotency\RegisteredOutcome;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\StateChangingCommand;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Domain\DomainTestCase;
use Tests\Domain\Shared\Doubles\ImmediateTransactionBoundary;
use Tests\Domain\Shared\Doubles\InMemoryIdempotencyRegistry;
use Tests\Domain\Shared\Doubles\TestRefusalReason;

/**
 * CMP-IMP-025 — idempotency at the application boundary.
 *
 * Level 2 (`TC-029` ‡). The registry's **race** behaviour is a property of a
 * unique constraint and belongs at level 3 (`TC-035`); what is provable without
 * a database is the sequencing: claim, work, record, and what happens on a
 * repeat.
 */
final class IdempotentOperationTest extends DomainTestCase
{
    private const OPERATION = 'ride.publish';

    #[Test]
    public function the_work_runs_once_and_its_outcome_is_recorded(): void
    {
        $registry = new InMemoryIdempotencyRegistry;
        $runs = 0;

        $result = $this->operation($registry)->execute(
            $this->command('key-1', 'fingerprint-a'),
            self::OPERATION,
            $this->actor(),
            function () use (&$runs): Result {
                $runs++;

                return Result::success(['rideId' => 'r-1']);
            },
        );

        self::assertTrue($result->isSuccess());
        self::assertSame(1, $runs);

        $outcome = $result->value();
        self::assertInstanceOf(RegisteredOutcome::class, $outcome);
        self::assertSame(['rideId' => 'r-1'], $outcome->representation());
        self::assertFalse($outcome->replayed());
    }

    #[Test]
    public function a_repeat_with_the_same_key_and_content_returns_the_original_and_produces_no_second_effect(): void
    {
        // API-062 ‡.
        $registry = new InMemoryIdempotencyRegistry;
        $operation = $this->operation($registry);
        $runs = 0;

        $work = function () use (&$runs): Result {
            $runs++;

            return Result::success(['rideId' => 'r-1']);
        };

        $operation->execute($this->command('key-1', 'fingerprint-a'), self::OPERATION, $this->actor(), $work);
        $replay = $operation->execute($this->command('key-1', 'fingerprint-a'), self::OPERATION, $this->actor(), $work);

        self::assertSame(1, $runs, 'API-062 ‡: a repeat produces no second effect.');
        self::assertTrue($replay->isSuccess());

        $outcome = $replay->value();
        self::assertInstanceOf(RegisteredOutcome::class, $outcome);
        self::assertSame(['rideId' => 'r-1'], $outcome->representation());
        self::assertTrue($outcome->replayed(), 'API-064: a replay is indicated as one.');
    }

    #[Test]
    public function a_repeat_with_the_same_key_and_different_content_is_refused_and_does_not_overwrite(): void
    {
        // Negative test for API-063 ‡.
        $registry = new InMemoryIdempotencyRegistry;
        $operation = $this->operation($registry);
        $runs = 0;

        $operation->execute(
            $this->command('key-1', 'fingerprint-a'),
            self::OPERATION,
            $this->actor(),
            function () use (&$runs): Result {
                $runs++;

                return Result::success(['rideId' => 'r-1']);
            },
        );

        $refused = $operation->execute(
            $this->command('key-1', 'fingerprint-b'),
            self::OPERATION,
            $this->actor(),
            function () use (&$runs): Result {
                $runs++;

                return Result::success(['rideId' => 'r-2']);
            },
        );

        self::assertSame(1, $runs, 'The second request must not run.');
        self::assertTrue($refused->isFailure());

        $failure = $refused->failure();
        self::assertInstanceOf(BusinessRefused::class, $failure);
        self::assertSame('idempotency.key_reused_with_different_content', $failure->identifier());

        self::assertSame(
            ['rideId' => 'r-1'],
            $registry->recordedOutcome($this->actor(), self::OPERATION, 'key-1'),
            'API-063 ‡: the original outcome must not be overwritten.',
        );
    }

    #[Test]
    public function the_same_key_under_a_different_operation_is_a_different_claim(): void
    {
        // API-060: the key is scoped to the acting actor and the operation.
        $registry = new InMemoryIdempotencyRegistry;
        $operation = $this->operation($registry);
        $runs = 0;

        $work = function () use (&$runs): Result {
            $runs++;

            return Result::success(null);
        };

        $operation->execute($this->command('key-1', 'f'), 'ride.publish', $this->actor(), $work);
        $operation->execute($this->command('key-1', 'f'), 'booking.request', $this->actor(), $work);

        self::assertSame(2, $runs);
    }

    #[Test]
    public function the_same_key_held_by_a_different_actor_is_a_different_claim(): void
    {
        $registry = new InMemoryIdempotencyRegistry;
        $operation = $this->operation($registry);
        $runs = 0;

        $work = function () use (&$runs): Result {
            $runs++;

            return Result::success(null);
        };

        $operation->execute($this->command('key-1', 'f'), self::OPERATION, ActorReference::fromString('actor-1'), $work);
        $operation->execute($this->command('key-1', 'f'), self::OPERATION, ActorReference::fromString('actor-2'), $work);

        self::assertSame(2, $runs);
    }

    #[Test]
    public function everything_happens_inside_one_transaction(): void
    {
        // BE-051 ‡ / DB-141 ‡ / API-061 ‡: the registry entry commits in the same
        // transaction as the effect it guards. BADR-08 records why the
        // alternative was rejected — a crash between the effect and a write
        // outside the transaction would permit a duplicate.
        $transaction = new ImmediateTransactionBoundary;
        $registry = new InMemoryIdempotencyRegistry;
        $registry->observedBy($transaction);

        $operation = new IdempotentOperation($transaction, $registry);

        $operation->execute(
            $this->command('key-1', 'f'),
            self::OPERATION,
            $this->actor(),
            static function () use ($transaction): Result {
                $transaction->record('work');

                return Result::success(['ok' => true]);
            },
        );

        self::assertSame(1, $transaction->openedScopes());
        self::assertSame(
            ['claim', 'work', 'recordOutcome'],
            $transaction->eventsWithin(),
            'The claim, the work and the outcome record must all fall inside one scope.',
        );
    }

    #[Test]
    public function a_failure_in_the_work_rolls_the_claim_back(): void
    {
        // BE-053 ‡: a failed operation leaves no partial effect. A claim that
        // survived a rolled-back transaction would lock the key against a retry
        // that never happened.
        $transaction = new ImmediateTransactionBoundary;
        $registry = new InMemoryIdempotencyRegistry;
        $registry->rollBackOn($transaction);

        $operation = new IdempotentOperation($transaction, $registry);

        try {
            $operation->execute(
                $this->command('key-1', 'f'),
                self::OPERATION,
                $this->actor(),
                static fn (): Result => throw new RuntimeException('the platform failed'),
            );
            self::fail('The throwable must propagate.');
        } catch (RuntimeException) {
            // Expected: BE-186 ‡ forbids representing a platform fault as a
            // refusal, so it is not caught here either.
        }

        self::assertNull($registry->existing($this->actor(), self::OPERATION, IdempotencyKey::fromString('key-1')));
    }

    #[Test]
    public function a_business_refusal_is_recorded_as_the_outcome(): void
    {
        // API-062 ‡ speaks of the original outcome. A refusal is an outcome: a
        // retry must receive the same answer rather than re-running the work.
        $registry = new InMemoryIdempotencyRegistry;
        $operation = $this->operation($registry);
        $runs = 0;

        $work = function () use (&$runs): Result {
            $runs++;

            return Result::failed(new BusinessRefused(TestRefusalReason::SeatsNoLongerAvailable));
        };

        $first = $operation->execute($this->command('key-1', 'f'), self::OPERATION, $this->actor(), $work);
        $second = $operation->execute($this->command('key-1', 'f'), self::OPERATION, $this->actor(), $work);

        self::assertTrue($first->isFailure());
        self::assertSame(1, $runs, 'The work must not run a second time.');
        self::assertTrue($second->isSuccess());

        $outcome = $second->value();
        self::assertInstanceOf(RegisteredOutcome::class, $outcome);
        self::assertTrue($outcome->replayed());
        self::assertNull($outcome->representation());
    }

    private function operation(InMemoryIdempotencyRegistry $registry): IdempotentOperation
    {
        return new IdempotentOperation(new ImmediateTransactionBoundary, $registry);
    }

    private function actor(): ActorReference
    {
        return ActorReference::fromString('actor-1');
    }

    private function command(string $key, string $fingerprint): StateChangingCommand
    {
        return new class($key, $fingerprint) implements StateChangingCommand
        {
            public function __construct(
                private readonly string $key,
                private readonly string $fingerprint,
            ) {}

            public function idempotencyKey(): IdempotencyKey
            {
                return IdempotencyKey::fromString($this->key);
            }

            public function contentFingerprint(): string
            {
                return $this->fingerprint;
            }
        };
    }
}
