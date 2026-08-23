<?php

declare(strict_types=1);

namespace Tests\Integration\Idempotency;

use Cmp\Application\Safety\RaiseIncidentCommand;
use Cmp\Application\Safety\RaiseSafetyIncident;
use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Idempotency\RegisteredOutcome;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\Shared\Result;
use Cmp\Application\User\AmendEmergencyContact;
use Cmp\Application\User\AuthenticatedCaller;
use Cmp\Application\User\CurrentSessionCommand;
use Cmp\Application\User\EmergencyContactCommand;
use Cmp\Application\User\EstablishSession;
use Cmp\Application\User\EstablishSessionCommand;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\NominateEmergencyContact;
use Cmp\Application\User\RefreshCurrentSession;
use Cmp\Application\User\RemoveEmergencyContact;
use Cmp\Application\User\ResolveSession;
use Cmp\Application\User\TerminateCurrentSession;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Persistence\Safety\DatabaseSafetyIncidentRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseEmergencyContactRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\StateChangingOperations;
use Tests\TestCase;

/**
 * `BE-211` ‡ — *"Idempotent replay shall be tested for **every** state-changing
 * operation."*
 *
 * **Level 3** (`TC-025`): a replay is a claim about what is committed, and only a
 * store can answer it.
 *
 * ## What this replaces, and why the replacement was needed
 *
 * `BE-211` ‡ was marked **enforced** in the obligation register, proven by
 * `IdempotencyRegistryTest`. That test proves the **registry** replays: it claims
 * a key, runs work, repeats the key and gets the recorded outcome back — under a
 * synthetic `test.operation` that no service performs.
 *
 * It could not prove this statement, and the distance between the two was not
 * academic. Until `CC-047`, `IdempotentOperation` was invoked by `PlatformJob`
 * and by nothing else, so **not one** of the operations below replayed — while
 * the register recorded the obligation as discharged. A mechanism that works,
 * tested where it works, standing in for a guarantee nothing had applied.
 *
 * So this test replays **each operation**, and the set of operations is
 * {@see StateChangingOperations}, **derived from the source**. A new
 * state-changing service cannot be added without a case here, because the
 * coverage assertion reads the code rather than a list somebody maintains.
 *
 * ## One key, twice, and then a count
 *
 * Each case runs the operation twice under one key and counts the effect in the
 * store. `API-062` ‡ requires *"no second effect"*, so the count is the
 * assertion; `API-064` requires a client to be able to tell the replay, so the
 * second outcome is asserted to say so.
 */
final class EveryStateChangingOperationReplaysTest extends TestCase
{
    use ClearsTheEvidentialLog;

    private const USER = 'add0001add0002add0003add0004add0';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();

        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test',
        );
        $this->app->make(ChangePolicyValue::class)->apply(
            PolicyServiceProvider::concurrentSessionLimit()->name(), '3', 'operator-under-test',
        );

        $this->insertUser();
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    /**
     * The coverage assertion, and the reason this file cannot fall behind.
     *
     * `BE-211` ‡ says **every**. The set is derived from the source, so a service
     * added without a case here fails this rather than passing unnoticed.
     */
    public function test_every_state_changing_operation_in_the_source_has_a_replay_case(): void
    {
        $derived = array_keys(StateChangingOperations::all());

        self::assertSame(
            [
                'AmendEmergencyContact',
                'EstablishSession',
                'NominateEmergencyContact',
                'RaiseSafetyIncident',
                'RefreshCurrentSession',
                'RemoveEmergencyContact',
                'TerminateCurrentSession',
            ],
            $derived,
            'BE-211 ‡: a state-changing operation was added or removed. Every one needs a replay case below.',
        );

        // Each name above has a method here that replays it. Asserted by name so
        // that deleting a case is a failure rather than a silent reduction.
        foreach ($derived as $operation) {
            self::assertTrue(
                method_exists($this, 'test_'.self::snake($operation).'_replays'),
                $operation.' is state-changing and has no replay case.',
            );
        }
    }

    public function test_establish_session_replays(): void
    {
        $key = $this->key('establish');

        $first = $this->establish($key);
        $second = $this->establish($key);

        $this->assertReplayed($first, $second);
        self::assertSame(1, $this->rowsIn(DatabaseSessionRepository::TABLE), 'One session, not two.');
    }

    public function test_terminate_current_session_replays(): void
    {
        $session = $this->aSession();
        $key = $this->key('terminate');

        $first = $this->app->make(TerminateCurrentSession::class)
            ->execute(new CurrentSessionCommand($session, $key), $this->actor());
        $second = $this->app->make(TerminateCurrentSession::class)
            ->execute(new CurrentSessionCommand($session, $key), $this->actor());

        $this->assertReplayed($first, $second);

        // DB-044 ‡: the row stays, terminated once. A second termination would
        // move the instant, which is what the registry prevents.
        self::assertSame(1, $this->rowsIn(DatabaseSessionRepository::TABLE));
        self::assertSame(1, $this->terminatedCount());
    }

    public function test_refresh_current_session_replays(): void
    {
        $session = $this->aSession();
        $key = $this->key('refresh');

        $first = $this->app->make(RefreshCurrentSession::class)
            ->execute(new CurrentSessionCommand($session, $key), $this->actor());
        $second = $this->app->make(RefreshCurrentSession::class)
            ->execute(new CurrentSessionCommand($session, $key), $this->actor());

        $this->assertReplayed($first, $second);

        // One refresh: the original plus one issued session, not two.
        self::assertSame(2, $this->rowsIn(DatabaseSessionRepository::TABLE));

        // SEC-038 ‡: the replay carries no token, because the registry was never
        // allowed to keep one. OperationOutcome is where that is decided.
        $replayed = $this->outcomeOf($second)->representation() ?? [];

        self::assertArrayNotHasKey('token', $replayed);
    }

    public function test_nominate_emergency_contact_replays(): void
    {
        $key = $this->key('nominate');

        $first = $this->nominate($key, '+910000005001');
        $second = $this->nominate($key, '+910000005001');

        $this->assertReplayed($first, $second);
        self::assertSame(1, $this->rowsIn(DatabaseEmergencyContactRepository::TABLE));
    }

    public function test_amend_emergency_contact_replays(): void
    {
        $id = $this->contactIdFrom($this->nominate($this->key('for-amend'), '+910000005002'));
        $key = $this->key('amend');

        $amend = fn (): Result => $this->app->make(AmendEmergencyContact::class)->execute(
            EmergencyContactCommand::toAmend($this->caller(), $key, $id, '+910000005003', 'Amended'),
            $this->actor(),
        );

        $first = $amend();
        $second = $amend();

        $this->assertReplayed($first, $second);

        $rows = $this->connection('mysql')->select(
            'SELECT phone_number FROM '.DatabaseEmergencyContactRepository::TABLE
        );

        self::assertCount(1, $rows);
        self::assertSame('+910000005003', $rows[0]->phone_number);
    }

    public function test_remove_emergency_contact_replays(): void
    {
        $id = $this->contactIdFrom($this->nominate($this->key('for-remove'), '+910000005004'));
        $key = $this->key('remove');

        $remove = fn (): Result => $this->app->make(RemoveEmergencyContact::class)->execute(
            EmergencyContactCommand::toRemove($this->caller(), $key, $id),
            $this->actor(),
        );

        $first = $remove();
        $second = $remove();

        $this->assertReplayed($first, $second);
        self::assertSame(0, $this->rowsIn(DatabaseEmergencyContactRepository::TABLE));
    }

    public function test_raise_safety_incident_replays(): void
    {
        $key = $this->key('raise');

        $raise = fn (): Result => $this->app->make(RaiseSafetyIncident::class)->execute(
            RaiseIncidentCommand::from($this->caller(), $key),
            $this->actor(),
        );

        $first = $raise();
        $second = $raise();

        $this->assertReplayed($first, $second);

        // FRD-FR-188 ‡ is not in tension with this: one signal was raised, and
        // one incident records it. A replay is the same signal arriving twice.
        self::assertSame(1, $this->rowsIn(DatabaseSafetyIncidentRepository::TABLE));
    }

    /**
     * `API-062` ‡ and `API-064` together: the second call produced no second
     * effect and says that it did not.
     */
    private function assertReplayed(Result $first, Result $second): void
    {
        self::assertTrue($first->isSuccess(), 'The first call must succeed for the replay to mean anything.');
        self::assertTrue($second->isSuccess());

        self::assertFalse($this->outcomeOf($first)->replayed(), 'The first call is not a replay.');
        self::assertTrue($this->outcomeOf($second)->replayed(), 'API-064: the second call is a replay and says so.');
    }

    private function outcomeOf(Result $result): RegisteredOutcome
    {
        $outcome = $result->value();

        self::assertInstanceOf(RegisteredOutcome::class, $outcome);

        return $outcome;
    }

    private function establish(IdempotencyKey $key): Result
    {
        return $this->app->make(EstablishSession::class)->execute(
            new EstablishSessionCommand(UserReference::fromString(self::USER), $key),
            $this->actor(),
        );
    }

    private function nominate(IdempotencyKey $key, string $number): Result
    {
        return $this->app->make(NominateEmergencyContact::class)->execute(
            EmergencyContactCommand::toNominate($this->caller(), $key, $number, null),
            $this->actor(),
        );
    }

    private function contactIdFrom(Result $result): string
    {
        $contact = ($this->outcomeOf($result)->representation() ?? [])['contact'] ?? null;

        self::assertIsArray($contact);
        self::assertIsString($contact['id']);

        return $contact['id'];
    }

    private function aSession(): Session
    {
        $tokens = $this->app->make(HashesSessionTokens::class);
        $session = Session::establish(
            UserReference::fromString(self::USER),
            $tokens->hash($tokens->generate()),
            $this->app->make(Clock::class)->now(),
        );

        $this->app->make(SessionRepository::class)->save($session);

        return $session;
    }

    private function caller(): AuthenticatedCaller
    {
        return new AuthenticatedCaller($this->aSession(), $this->actor());
    }

    private function actor(): Actor
    {
        return Actor::holding(ActorReference::fromString(self::USER), []);
    }

    private function key(string $seed): IdempotencyKey
    {
        return IdempotencyKey::fromString('replay-'.substr(hash('sha256', $seed), 0, 24));
    }

    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private function rowsIn(string $table): int
    {
        $rows = $this->connection('mysql')->select('SELECT COUNT(*) AS total FROM '.$table);

        return (int) $rows[0]->total;
    }

    private function terminatedCount(): int
    {
        $rows = $this->connection('mysql')->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseSessionRepository::TABLE.' WHERE terminated_at IS NOT NULL'
        );

        return (int) $rows[0]->total;
    }

    private function insertUser(): void
    {
        $this->connection('mysql')->insert(
            'INSERT INTO '.DatabaseUserRepository::TABLE
            .' (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [
                self::USER, '+910000005000', 'VERIFIED', 'ACTIVE',
                '2026-08-22 09:00:00.000000', '2026-08-22 09:00:00.000000',
            ],
        );
    }

    private function connection(string $name): Connection
    {
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection($name);

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function clearAll(): void
    {
        $migration = $this->connection('mysql_migration');

        $migration->delete('DELETE FROM '.DatabaseSafetyIncidentRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseEmergencyContactRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseSessionRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseUserRepository::TABLE);

        $this->clearEvidentialLog();
    }
}
