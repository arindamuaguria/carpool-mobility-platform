<?php

declare(strict_types=1);

namespace Tests\System;

use Cmp\Application\Safety\MarkIncidentRouted;
use Cmp\Application\Safety\RaiseSafetyIncident;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Job\RouteSafetyIncident;
use Cmp\Infrastructure\Persistence\Safety\DatabaseSafetyIncidentRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\SessionCarriage;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Illuminate\Testing\TestResponse;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * `UC-051` end to end — and the promise the platform must not make.
 *
 * **Level 5** (`TC-025`): a real HTTP request through the real middleware, the
 * real application service, a real MySQL and the real queue. Two things only
 * this level can say.
 *
 * - The **signal reaches the queue**: raised over HTTP, dispatched onto the
 *   `safety` family, worked by the job, and marked routed.
 * - **`FRD-FR-195` ‡ / `UX-165` ‡ / `NFR-137`** are about what is *presented*,
 *   so they can only be checked against what is actually served. This is the
 *   surface where getting that wrong would matter most: `BAD-DEC-011` is open,
 *   no response capability is staffed, and `BAD-RISK-005` is the risk that a
 *   safety affordance with nothing behind it is a liability.
 */
final class SafetyIncidentEndpointTest extends TestCase
{
    use ClearsTheEvidentialLog;

    private const RAISER = 'beef1001beef1002beef1003beef1004';

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test',
        );

        $this->insertUser(self::RAISER, '+910000004001');
        $this->token = $this->establish(self::RAISER);
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_signal_raised_over_http_reaches_the_safety_queue(): void
    {
        // FRD-FR-189 ‡ / BE-132 ‡. The job is dispatched onto the `safety`
        // family and nothing else, because BADR-05 gives it the highest priority
        // and SRS-REQ-060 requires safety work ahead of all other.
        Queue::fake();

        $this->raise()->assertOk();

        Queue::assertPushedOn('safety', RouteSafetyIncident::class);
    }

    public function test_the_incident_is_marked_routed_once_the_job_runs(): void
    {
        // The whole pipeline, with the real queue driver: raised, dispatched,
        // worked, marked. FRD-FR-189 ‡ end to end.
        $id = $this->raise()->json('data.incident.id');

        self::assertIsString($id);

        $this->workTheSafetyQueue();

        $rows = $this->connection('mysql')->select(
            'SELECT routed_at FROM '.DatabaseSafetyIncidentRepository::TABLE.' WHERE external_id = ?',
            [$id],
        );

        self::assertCount(1, $rows);
        self::assertNotNull($rows[0]->routed_at, 'FRD-FR-189 ‡: the incident reached the operator queue.');

        // And the raiser can see it. API-171 ‡ keeps closure away from the
        // client interface; nothing here closes anything.
        $this->send('GET', 'safety/v1/incidents/'.$id)
            ->assertOk()
            ->assertJsonPath('data.incident.routed', true);
    }

    public function test_the_pipeline_is_evidenced_end_to_end(): void
    {
        // UC-051 step 4 / UC-079 / FRD-FR-248 ‡. Both halves are recorded, so
        // "it was raised at 14:02 and reached the queue at 14:02" is answerable
        // from the evidential log rather than from a worker's memory.
        $id = $this->raise()->json('data.incident.id');

        self::assertIsString($id);

        $this->workTheSafetyQueue();

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString(RaiseSafetyIncident::ACTION, $encoded);
        self::assertStringContainsString(MarkIncidentRouted::ACTION, $encoded);
        self::assertStringContainsString($id, $encoded);
    }

    public function test_the_retry_command_dispatches_what_never_reached_the_queue(): void
    {
        // FRD-FR-190 ‡, through the command an operator actually runs. It is not
        // scheduled — BE-148 makes scheduled-work frequency policy configuration
        // and none is set — so this is how the retry runs today.
        Queue::fake();

        $this->raise()->assertOk();

        // Pretend it never arrived: the row is the retention, so clearing the
        // mark is the whole of "it did not reach the queue".
        $this->connection('mysql_migration')->update(
            'UPDATE '.DatabaseSafetyIncidentRepository::TABLE.' SET routed_at = NULL'
        );

        $retry = $this->artisan('safety:route-pending');

        self::assertInstanceOf(PendingCommand::class, $retry);
        $retry->assertExitCode(0);

        Queue::assertPushedOn('safety', RouteSafetyIncident::class);
    }

    /**
     * `FRD-FR-195` ‡ / `UX-165` ‡ / `UX-176` ‡ / `NFR-137`, against the served
     * bytes.
     *
     * *"The system shall not present a safety control that the platform cannot
     * honour."* `BAD-DEC-011` is open, `BRD-REQ-113` — the response protocol —
     * is Blocked, `BRD-REQ-114` — informing emergency contacts — is Blocked, and
     * `GAP-004` leaves emergency dispatch undecided. CMP-DOC-10 §12.4 states the
     * consequence for this surface directly: *"the interface states nothing
     * about dispatch."*
     *
     * So nothing this surface returns may suggest that anybody is coming. A user
     * who has just raised a safety signal is the least able to read carefully
     * and the most likely to act on what they think it said, which is why
     * `BAD-RISK-005` treats an unbacked affordance as a liability rather than a
     * cosmetic problem.
     */
    public function test_nothing_the_safety_surface_serves_promises_a_response(): void
    {
        $id = $this->raise()->json('data.incident.id');

        self::assertIsString($id);

        $served = implode("\n", [
            (string) $this->raise()->getContent(),
            (string) $this->send('GET', 'safety/v1/incidents/'.$id)->getContent(),
            // The refusals too — a refusal is presented text, and "we could not
            // alert anybody" would imply an alert exists.
            (string) $this->send('GET', 'safety/v1/incidents/'.str_repeat('c', 32))->getContent(),
            (string) $this->send('POST', 'safety/v9/incidents')->getContent(),
        ]);

        $forbidden = [
            'sos', 'emergency service', 'help is', 'on the way', 'assistance',
            'responder', 'rescue', 'dispatch', 'notified', 'alerted', 'informed',
            'protection', 'protected', 'we will contact', 'someone is', 'insurance',
        ];

        foreach ($forbidden as $claim) {
            self::assertStringNotContainsStringIgnoringCase(
                $claim,
                $served,
                sprintf(
                    'FRD-FR-195 ‡ / NFR-137: the safety surface served "%s". BAD-DEC-011 is open and no '
                    .'response capability is staffed — BAD-RISK-005 is the risk this assertion exists for.',
                    $claim,
                ),
            );
        }
    }

    /**
     * Drain the `safety` family, and only that one.
     *
     * `BE-132` ‡: safety work has its own queue and a worker may be assigned
     * per family. Naming it here is the test doing what a deployment does.
     */
    private function workTheSafetyQueue(): void
    {
        $worker = $this->artisan('queue:work', [
            '--queue' => 'safety',
            '--stop-when-empty' => true,
        ]);

        self::assertInstanceOf(PendingCommand::class, $worker);
        $worker->assertExitCode(0);
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function raise(): TestResponse
    {
        return $this->send('POST', 'safety/v1/incidents');
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function send(string $method, string $path): TestResponse
    {
        return $this->withHeaders([
            RequireIdempotencyKey::HEADER => 'system-safety-'.substr(hash('sha256', $method.$path.uniqid()), 0, 16),
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$this->token,
        ])->json($method, '/'.$path);
    }

    private function establish(string $reference): string
    {
        $tokens = $this->app->make(HashesSessionTokens::class);
        $token = $tokens->generate();

        $this->app->make(SessionRepository::class)->save(Session::establish(
            UserReference::fromString($reference),
            $tokens->hash($token),
            $this->app->make(Clock::class)->now(),
        ));

        return $token;
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
