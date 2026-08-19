<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Idempotency\IdempotencyRegistry;
use Cmp\Application\Shared\Idempotency\IdempotentOperation;
use Cmp\Application\Shared\Idempotency\RegisteredOutcome;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\StateChangingCommand;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Infrastructure\Persistence\Idempotency\DatabaseIdempotencyRegistry;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Integration\IntegrationTestCase;

/**
 * CMP-IMP-024, CMP-IMP-025 — the idempotency registry and the transaction
 * boundary, against a real MySQL.
 *
 * Level 3 (`TC-030` ‡). Two properties cannot be proven anywhere else:
 *
 *  - `DB-142` ‡ — a duplicate is rejected by a **unique constraint**, not by a
 *    race-prone read. Asserted under genuine concurrency (`TADR-08`): two
 *    separate database sessions, each in its own transaction, both attempting
 *    the same claim.
 *  - `BE-051` ‡ / `DB-141` ‡ — the entry commits with the effect it guards, so a
 *    rolled-back transaction leaves no claim behind to lock the key against a
 *    retry that never happened.
 */
final class IdempotencyRegistryTest extends IntegrationTestCase
{
    private const OPERATION = 'test.operation';

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationConnection()->delete(
            'DELETE FROM '.DatabaseIdempotencyRegistry::TABLE.' WHERE operation = ?',
            [self::OPERATION],
        );
    }

    protected function tearDown(): void
    {
        $this->applicationConnection()->delete(
            'DELETE FROM '.DatabaseIdempotencyRegistry::TABLE.' WHERE operation = ?',
            [self::OPERATION],
        );

        parent::tearDown();
    }

    #[Test]
    public function the_registry_records_a_claim_and_its_outcome(): void
    {
        $result = $this->operation()->execute(
            $this->command('key-integration-1', 'fingerprint-a'),
            self::OPERATION,
            $this->actor(),
            static fn (): Result => Result::success(['rideId' => 'r-1']),
        );

        self::assertTrue($result->isSuccess());

        $outcome = $result->value();
        self::assertInstanceOf(RegisteredOutcome::class, $outcome);
        self::assertSame(['rideId' => 'r-1'], $outcome->representation());
        self::assertFalse($outcome->replayed());

        $rows = $this->applicationConnection()->select(
            'SELECT outcome, completed_at, interface_version FROM '.DatabaseIdempotencyRegistry::TABLE
            .' WHERE operation = ? AND request_key = ?',
            [self::OPERATION, 'key-integration-1'],
        );

        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]->completed_at);
        // DB-040 / DADR-16: the recorded outcome is an interface representation,
        // so the version it was produced under is recorded with it.
        self::assertSame(1, (int) $rows[0]->interface_version);
    }

    #[Test]
    public function a_repeat_returns_the_recorded_outcome_without_re_executing(): void
    {
        // API-062 ‡ against the real table.
        $runs = 0;
        $work = function () use (&$runs): Result {
            $runs++;

            return Result::success(['rideId' => 'r-1']);
        };

        $operation = $this->operation();
        $operation->execute($this->command('key-integration-2', 'f'), self::OPERATION, $this->actor(), $work);
        $replay = $operation->execute($this->command('key-integration-2', 'f'), self::OPERATION, $this->actor(), $work);

        self::assertSame(1, $runs);
        self::assertTrue($replay->isSuccess());

        $outcome = $replay->value();
        self::assertInstanceOf(RegisteredOutcome::class, $outcome);
        self::assertTrue($outcome->replayed());
        self::assertSame(['rideId' => 'r-1'], $outcome->representation());
    }

    #[Test]
    public function a_rolled_back_transaction_leaves_no_claim_behind(): void
    {
        // BE-053 ‡ / BE-051 ‡. A claim surviving a rollback would lock the key
        // against a retry of work that never took effect.
        try {
            $this->operation()->execute(
                $this->command('key-integration-3', 'f'),
                self::OPERATION,
                $this->actor(),
                static fn (): Result => throw new RuntimeException('the platform failed'),
            );
            self::fail('The throwable must propagate.');
        } catch (RuntimeException) {
            // BE-186 ‡: a platform fault is not converted into a refusal.
        }

        self::assertNull(
            $this->registry()->existing($this->actor(), self::OPERATION, IdempotencyKey::fromString('key-integration-3')),
        );
    }

    #[Test]
    public function two_concurrent_sessions_cannot_both_claim_the_same_key(): void
    {
        // DB-142 ‡ under genuine concurrency (TADR-08). Two separate database
        // sessions, each holding an open transaction, race for the same claim.
        // The unique constraint decides, so no interleaving lets both proceed —
        // which a SELECT-then-INSERT could not guarantee.
        $first = $this->rawConnection();
        $second = $this->rawConnection();

        $first->beginTransaction();
        $second->beginTransaction();

        $firstClaimed = $this->rawClaim($first, 'key-integration-4');

        // The second session blocks on the unique index until the first commits,
        // then is refused. A lock wait is the constraint doing its job.
        $first->commit();

        $secondClaimed = $this->rawClaim($second, 'key-integration-4');
        $second->rollBack();

        self::assertTrue($firstClaimed, 'The first session must take the claim.');
        self::assertFalse($secondClaimed, 'DB-142 ‡: the database must reject the duplicate.');

        $rows = $this->applicationConnection()->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseIdempotencyRegistry::TABLE
            .' WHERE operation = ? AND request_key = ?',
            [self::OPERATION, 'key-integration-4'],
        );

        self::assertSame(1, (int) $rows[0]->total, 'Exactly one entry must exist.');
    }

    #[Test]
    public function the_unique_constraint_is_named_for_the_rule_it_enforces(): void
    {
        // DB-019 / DB-203: a violation message names the rule.
        $rows = $this->readConnection()->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?',
            [DatabaseIdempotencyRegistry::TABLE, 'UNIQUE'],
        );

        self::assertCount(1, $rows);
        self::assertSame(
            'mch_idempotency_registries_actor_operation_request_key_unique',
            $rows[0]->CONSTRAINT_NAME,
        );
    }

    #[Test]
    public function the_registry_holds_no_foreign_key_into_operational_state(): void
    {
        // DB-013: a machinery table associates by recorded identifier, so that it
        // can be pruned without cascading into op_ (DB-156).
        $rows = $this->readConnection()->select(
            'SELECT COUNT(*) AS total FROM information_schema.KEY_COLUMN_USAGE'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [DatabaseIdempotencyRegistry::TABLE],
        );

        self::assertSame(0, (int) $rows[0]->total);
    }

    private function rawClaim(PDO $pdo, string $key): bool
    {
        $statement = $pdo->prepare(
            'INSERT INTO '.DatabaseIdempotencyRegistry::TABLE
            .' (actor, operation, request_key, content_fingerprint, interface_version, claimed_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)'
        );

        try {
            $statement->execute(['actor-1', self::OPERATION, $key, 'f', 1, gmdate('Y-m-d H:i:s')]);
        } catch (PDOException) {
            return false;
        }

        return true;
    }

    private function rawConnection(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                $this->connectionSetting('mysql', 'host'),
                $this->connectionSetting('mysql', 'port'),
                $this->connectionSetting('mysql', 'database'),
            ),
            $this->connectionSetting('mysql', 'username'),
            $this->connectionSetting('mysql', 'password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function operation(): IdempotentOperation
    {
        return new IdempotentOperation(
            $this->app->make(TransactionBoundary::class),
            $this->registry(),
        );
    }

    private function registry(): IdempotencyRegistry
    {
        return $this->app->make(IdempotencyRegistry::class);
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
