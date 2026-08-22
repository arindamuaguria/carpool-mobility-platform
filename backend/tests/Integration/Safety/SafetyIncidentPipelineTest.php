<?php

declare(strict_types=1);

namespace Tests\Integration\Safety;

use Cmp\Application\Safety\IncidentView;
use Cmp\Application\Safety\RaiseIncidentCommand;
use Cmp\Application\Safety\RaiseSafetyIncident;
use Cmp\Application\Safety\RetrySafetyRouting;
use Cmp\Application\Safety\RoutesSafetyIncidents;
use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\User\AuthenticatedCaller;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Domain\Safety\IncidentReference;
use Cmp\Domain\Safety\SafetyIncidentRepository;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Persistence\Safety\DatabaseSafetyIncidentRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use RuntimeException;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * `UC-051` against a real store — the pipeline, and the case it exists for.
 *
 * **Level 3** (`TC-025`, `TC-030`): a real MySQL. Three things can only be
 * answered here.
 *
 * - **`FRD-FR-190` ‡** — *"retain and retry an incident that cannot immediately
 *   reach the operator queue."* Retention is a row, and only a store has one.
 * - **`API-169` ‡** — acceptance acknowledged only after the signal is
 *   persisted, which is a claim about what is committed when.
 * - **`FRD-FR-248` ‡ / `UC-051` step 4** — the evidential record exists.
 */
final class SafetyIncidentPipelineTest extends TestCase
{
    use ClearsTheEvidentialLog;

    private const RAISER = 'dead0001dead0002dead0003dead0004';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->insertUser(self::RAISER, '+910000002001');
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_signal_is_persisted_and_routed(): void
    {
        // FRD-FR-185 ‡ and FRD-FR-189 ‡ together, through the real dispatcher.
        $view = $this->raise('raise-1');

        $rows = $this->incidentRows();

        self::assertCount(1, $rows);
        self::assertSame($view->toArray()['id'], $rows[0]->external_id);

        // Every context element carries a standing — DB-078 ‡ — and every one is
        // unavailable, which is true and is what FRD-FR-187 ‡ asks be recorded.
        foreach (['trip_standing', 'vehicle_standing', 'co_travellers_standing', 'location_standing'] as $column) {
            self::assertSame('unavailable', $rows[0]->{$column});
        }
    }

    public function test_the_raise_is_evidenced_and_the_record_names_the_incident_only(): void
    {
        // FRD-FR-248 ‡ / UC-051 step 4 / BE-201 ‡. An incident's circumstances
        // are the most sensitive thing the platform holds, and DB-125 ‡ makes
        // the log append-only — anything written here could not be taken back.
        $view = $this->raise('raise-2');

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString(RaiseSafetyIncident::ACTION, $encoded);
        self::assertStringContainsString($view->toArray()['id'], $encoded);
        self::assertStringNotContainsString('+910000002001', $encoded);
    }

    public function test_a_queue_that_will_not_take_the_incident_does_not_lose_it(): void
    {
        // FRD-FR-188 ‡ and FRD-FR-190 ‡, which is the whole reason routing sits
        // outside the transaction. UC-051 E2: "the incident is recorded and
        // retried. It is never dropped."
        $this->app->instance(RoutesSafetyIncidents::class, new RefusingRouter);
        $this->app->forgetInstance(RaiseSafetyIncident::class);

        $view = $this->raise('raise-3');

        // Recorded — and API-169 ‡'s acknowledgement rests on that, not on the
        // queue.
        self::assertCount(1, $this->incidentRows());
        self::assertFalse($view->toArray()['routed']);

        // Retained: findable again by query, because a process that died here
        // would have remembered nothing.
        $pending = $this->app->make(SafetyIncidentRepository::class)->unrouted(10);

        self::assertSame(1, $pending->count());
        self::assertSame($view->toArray()['id'], $pending->all()[0]->reference()->toString());

        // BE-138 ‡: the deferral is visible immediately rather than discovered
        // later.
        self::assertStringContainsString(
            RaiseSafetyIncident::ROUTING_DEFERRED_ACTION,
            json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_the_retry_dispatches_every_incident_that_never_reached_the_queue(): void
    {
        // FRD-FR-190 ‡'s second half. The retry is a query, so it finds an
        // incident whichever way the first attempt failed.
        $this->app->instance(RoutesSafetyIncidents::class, new RefusingRouter);
        $this->app->forgetInstance(RaiseSafetyIncident::class);

        $this->raise('raise-4');
        $this->raise('raise-5');

        // The queue is available again.
        $router = new CountingRouter;
        $this->app->instance(RoutesSafetyIncidents::class, $router);
        $this->app->forgetInstance(RetrySafetyRouting::class);

        $outcome = $this->app->make(RetrySafetyRouting::class)->execute();

        // `more` is false: the batch of two did not fill BATCH, so the backlog is
        // clear. BD-04\u2019s distinction, reported rather than inferred.
        self::assertSame(['attempted' => 2, 'dispatched' => 2, 'more' => false], $outcome);
        self::assertSame(2, $router->dispatched);
    }

    public function test_one_incident_the_queue_refuses_does_not_stop_the_rest(): void
    {
        // FRD-FR-188 ‡. A pass that abandoned the batch on the first failure
        // would let one bad record hold up every signal behind it.
        $this->app->instance(RoutesSafetyIncidents::class, new RefusingRouter);
        $this->app->forgetInstance(RaiseSafetyIncident::class);

        $this->raise('raise-6');
        $this->raise('raise-7');

        $router = new SelectivelyRefusingRouter;
        $this->app->instance(RoutesSafetyIncidents::class, $router);
        $this->app->forgetInstance(RetrySafetyRouting::class);

        $outcome = $this->app->make(RetrySafetyRouting::class)->execute();

        self::assertSame(2, $outcome['attempted']);
        self::assertSame(1, $outcome['dispatched']);
    }

    public function test_the_unrouted_query_is_bounded_and_oldest_first(): void
    {
        // BD-04: an unbounded read of this table is the one query that must not
        // fall over when it matters most. Oldest first, because the earliest
        // signal is the one an operator draining a backlog needs.
        $this->app->instance(RoutesSafetyIncidents::class, new RefusingRouter);
        $this->app->forgetInstance(RaiseSafetyIncident::class);

        $first = $this->raise('raise-8')->toArray()['id'];
        $this->raise('raise-9');
        $this->raise('raise-10');

        $pending = $this->app->make(SafetyIncidentRepository::class)->unrouted(2);

        self::assertSame(2, $pending->count());
        self::assertSame($first, $pending->all()[0]->reference()->toString());

        // BD-04: a full batch says nothing about whether the backlog is clear.
        self::assertTrue($pending->mayBeMore());
    }

    private function raise(string $seed): IncidentView
    {
        $result = $this->app->make(RaiseSafetyIncident::class)->execute(
            RaiseIncidentCommand::from($this->caller(), $this->key($seed)),
            $this->actor(),
        );

        self::assertTrue($result->isSuccess());

        $view = $result->value();

        self::assertInstanceOf(IncidentView::class, $view);

        return $view;
    }

    private function caller(): AuthenticatedCaller
    {
        $tokens = $this->app->make(HashesSessionTokens::class);
        $session = Session::establish(
            UserReference::fromString(self::RAISER),
            $tokens->hash($tokens->generate()),
            $this->app->make(Clock::class)->now(),
        );

        $this->app->make(SessionRepository::class)->save($session);

        return new AuthenticatedCaller($session, $this->actor());
    }

    private function actor(): Actor
    {
        return Actor::holding(ActorReference::fromString(self::RAISER), []);
    }

    private function key(string $seed): IdempotencyKey
    {
        return IdempotencyKey::fromString('safety-'.substr(hash('sha256', $seed), 0, 24));
    }

    /**
     * @return list<object{external_id: string, trip_standing: string, vehicle_standing: string, co_travellers_standing: string, location_standing: string, routed_at: ?string}>
     */
    private function incidentRows(): array
    {
        /** @var list<object{external_id: string, trip_standing: string, vehicle_standing: string, co_travellers_standing: string, location_standing: string, routed_at: ?string}> $rows */
        $rows = $this->connection('mysql')->select(
            'SELECT external_id, trip_standing, vehicle_standing, co_travellers_standing, location_standing,'
            .' routed_at FROM '.DatabaseSafetyIncidentRepository::TABLE.' ORDER BY id ASC'
        );

        return $rows;
    }

    /**
     * @return list<object{actor: string, action: string, subject: string}>
     */
    private function evidentialRows(): array
    {
        /** @var list<object{actor: string, action: string, subject: string}> $rows */
        $rows = $this->connection('mysql')->select(
            'SELECT actor, action, subject FROM '.DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
        );

        return $rows;
    }

    private function insertUser(string $reference, string $phone): void
    {
        $this->connection('mysql')->insert(
            'INSERT INTO '.DatabaseUserRepository::TABLE
            .' (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [
                $reference, $phone, 'VERIFIED', 'ACTIVE',
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
        $migration->delete('DELETE FROM '.DatabaseSessionRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseUserRepository::TABLE);

        $this->clearEvidentialLog();
    }
}

/**
 * A queue that will not take anything.
 *
 * `UC-051` E2's condition, induced rather than waited for. `OPS-099` keeps
 * failure-induction hooks out of the production artefact, and this is in
 * `tests/` — it is a test double, not a switch the platform carries.
 */
final class RefusingRouter implements RoutesSafetyIncidents
{
    public function route(IncidentReference $reference, ActorReference $actor): void
    {
        throw new RuntimeException('The operator queue is unreachable.');
    }
}

final class CountingRouter implements RoutesSafetyIncidents
{
    public int $dispatched = 0;

    public function route(IncidentReference $reference, ActorReference $actor): void
    {
        $this->dispatched++;
    }
}

/**
 * Takes the first and refuses the second, so that one bad record can be shown
 * not to hold up the ones behind it.
 */
final class SelectivelyRefusingRouter implements RoutesSafetyIncidents
{
    private int $seen = 0;

    public function route(IncidentReference $reference, ActorReference $actor): void
    {
        $this->seen++;

        if ($this->seen > 1) {
            throw new RuntimeException('The operator queue will not take this one.');
        }
    }
}
