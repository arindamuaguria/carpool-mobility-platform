<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Persistence\Safety\DatabaseSafetyIncidentRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\SessionCarriage;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `/safety/v1/incidents` — the shape of the safety surface.
 *
 * **Level 4** (`TC-025`, `TC-031`): shape, never business behaviour. That an
 * unrouted incident is retried is `FRD-FR-190` ‡'s and belongs at level 3; what
 * is here is that the surface answers where `API-163` ‡ says it does, in the
 * shape `API-164` ‡ requires, with the error model `API-174` gives it.
 */
final class SafetyIncidentsTest extends TestCase
{
    private const RAISER = 'c0de0001c0de0002c0de0003c0de0004';

    private const OTHER = 'c0de1001c0de1002c0de1003c0de1004';

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test',
        );

        $this->insertUser(self::RAISER, '+910000003001');
        $this->insertUser(self::OTHER, '+910000003002');
        $this->token = $this->establish(self::RAISER);
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_the_surface_answers_under_its_own_prefix_and_not_the_general_one(): void
    {
        // API-163 ‡. AADR-11's reason is operational: a gateway rule written for
        // the general case must not reach safety traffic.
        $this->send('POST', 'safety/v1/incidents')->assertOk();

        // The same operation is not on the general surface. API-170 ‡ requires a
        // deployment implementing only safety operations to be possible.
        $this->send('POST', 'api/v1/incidents')->assertStatus(404);
    }

    public function test_a_field_the_operation_does_not_want_does_not_lose_the_signal(): void
    {
        // Two ‡ statements meet here and they point opposite ways.
        //
        // API-038 ‡ refuses a request carrying a field the schema does not
        // define, **in whole**. API-164 ‡ requires the raising shape to be "the
        // minimum that permits recording, so that a signal is never lost to a
        // validation failure on a non-essential field", and FRD-FR-188 ‡ says
        // the platform "shall never discard a safety signal".
        //
        // A schema defining no fields would make **every** field undefined, so
        // API-038 ‡ would turn any stray key into a lost signal — which is the
        // outcome CMP-DOC-10 §12.3 rejects in five words: "a rejected signal is a
        // lost signal."
        //
        // CMP-DOC-04 governs over CMP-DOC-10 (README §3), so FRD-FR-188 ‡
        // decides it: the safety surface carries **no request schema**, and a
        // field it did not ask for is recorded around rather than refused. The
        // conflict is reported at CC-045 rather than resolved quietly.
        $response = $this->send('POST', 'safety/v1/incidents', ['note' => 'anything']);

        $response->assertOk();
        self::assertSame(1, $this->incidentCount());

        // And nothing the caller sent is stored: the incident records what the
        // platform captured (FRD-FR-186 ‡), never what was posted at it.
        $incident = $response->json('data.incident');

        self::assertIsArray($incident);
        self::assertArrayNotHasKey('note', $incident);
    }

    public function test_an_incident_answers_with_its_reference_and_its_context_standings(): void
    {
        $response = $this->send('POST', 'safety/v1/incidents');

        $response->assertOk();
        $response->assertJsonPath('meta.interface_version', 1);

        $incident = $response->json('data.incident');

        self::assertIsArray($incident);
        self::assertSame(['id', 'raised_at', 'routed', 'context'], array_keys($incident));

        $id = $incident['id'];
        $context = $incident['context'];

        self::assertIsString($id);
        self::assertIsArray($context);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);

        // DB-078 ‡ / FRD-FR-187 ‡: every element carries a standing, and the
        // caller can see what the platform does not know.
        self::assertSame(['trip', 'vehicle', 'co_travellers', 'location'], array_keys($context));
    }

    public function test_the_envelope_carries_no_configuration_version(): void
    {
        // API-194 ‡ / CMP-DOC-10 §12.3: raising an incident does not depend on
        // configuration, and stamping a version would be that dependency however
        // tolerantly the read failed.
        $meta = $this->send('POST', 'safety/v1/incidents')->json('meta');

        self::assertIsArray($meta);

        // The key stays, because the envelope is one shape across both
        // surfaces (API-072 ‡ branches on structure). What matters is that it
        // is **null**: nothing on this path read a configuration version, so
        // there is nothing to report. SafetySurfaceRulesTest checks the other
        // half — that no safety file names a configuration reader at all.
        self::assertArrayHasKey('configuration_version', $meta);
        self::assertNull($meta['configuration_version']);
    }

    /**
     * The honesty test — `API-062` ‡ is **not** enforced on any REST
     * operation, and this one is no exception.
     *
     * `API-173` wants a repeated raise under a poor connection to produce one
     * incident, which is exactly the situation a client in an emergency is in.
     * It does not, and the cause is not this feature’s: `RequireIdempotencyKey`
     * checks the key is **present** and stops there, by design — `API-061` ‡
     * puts the registry entry in "the same transaction as the effect it
     * guards", which is the application service’s. And `IdempotentOperation`,
     * which is that mechanism, is invoked by `PlatformJob` and by nothing else.
     *
     * So every state-changing REST operation on the platform carries a key it
     * does nothing with. Recorded at **`CC-045`**, and asserted here rather
     * than left to be discovered: when the registry is wired into
     * `ApplicationService`, this test fails and is replaced by the one
     * `API-173` actually asks for.
     */
    public function test_a_repeated_raise_is_not_yet_replayed(): void
    {
        $first = $this->send('POST', 'safety/v1/incidents', [], 'one-signal');
        $second = $this->send('POST', 'safety/v1/incidents', [], 'one-signal');

        $first->assertOk();
        $second->assertOk();

        self::assertSame(
            2,
            $this->incidentCount(),
            'API-062 ‡ is now enforced on the REST surface — CC-045 is closed, and this test should assert '
            .'the one incident API-173 requires.',
        );

        // FRD-FR-188 ‡ is not breached by this: nothing is lost. Two records
        // where one was meant is a duplicate an operator can see and reconcile;
        // the failure mode API-062 ‡ exists to prevent is the opposite one.
    }

    public function test_a_raiser_reads_their_own_incident(): void
    {
        $id = $this->send('POST', 'safety/v1/incidents')->json('data.incident.id');

        self::assertIsString($id);

        $this->send('GET', 'safety/v1/incidents/'.$id)
            ->assertOk()
            ->assertJsonPath('data.incident.id', $id);
    }

    public function test_somebody_elses_incident_is_indistinguishable_from_one_that_does_not_exist(): void
    {
        // SEC-069 ‡ / API-094 ‡, and this is the record where it matters most —
        // a distinguishable "no such incident" would be a way to test whether
        // somebody has raised a safety signal.
        $id = $this->send('POST', 'safety/v1/incidents')->json('data.incident.id');

        self::assertIsString($id);

        $theirToken = $this->establish(self::OTHER);

        $theirs = $this->withHeaders([
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$theirToken,
            RequireIdempotencyKey::HEADER => 'contract-safety-other',
        ])->json('GET', '/safety/v1/incidents/'.$id);

        $absent = $this->send('GET', 'safety/v1/incidents/'.str_repeat('a', 32));
        $malformed = $this->send('GET', 'safety/v1/incidents/not-a-reference');

        foreach ([$theirs, $absent, $malformed] as $response) {
            // RefusalKind::RuleDeclined maps to 422 \u2014 the caller is not entitled,
            // which is not a conflict with state they can resolve.
            $response->assertStatus(422);
            $response->assertJsonPath('refusal.reason', 'access.not_available_to_you');
        }
    }

    public function test_the_surface_requires_a_session(): void
    {
        // API-095 ‡ / §9.1: raising an incident is not among the five operations
        // reachable without one.
        $this->withHeaders([RequireIdempotencyKey::HEADER => 'contract-safety-anon'])
            ->json('POST', '/safety/v1/incidents')
            ->assertStatus(409);
    }

    public function test_an_unserved_version_is_refused_distinctly(): void
    {
        // API-024 ‡ reaches this surface too — §12 says it answers on the same
        // contract, so a client on an unsupported version learns that rather
        // than meeting the 404 that statement forbids. 426 Upgrade Required is
        // the distinct outcome the general surface already serves.
        $this->send('POST', 'safety/v9/incidents')->assertStatus(426);
    }

    public function test_an_authoritative_value_is_refused_here_as_anywhere(): void
    {
        // FRD-FR-238 ‡ is about **every** request, and the safety surface is not
        // an exception to it.
        $response = $this->send('POST', 'safety/v1/incidents', ['tripState' => 'COMPLETED']);

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.field', 'trip state');
        $response->assertJsonPath('invalid_request.fields.0.reason', 'request.authoritative_value_asserted');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function send(string $method, string $path, array $body = [], ?string $key = null): TestResponse
    {
        return $this->withHeaders([
            RequireIdempotencyKey::HEADER => $key ?? 'contract-safety-'.substr(hash('sha256', $method.$path.serialize($body)), 0, 16),
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$this->token,
        ])->json($method, '/'.$path, $body);
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

    private function incidentCount(): int
    {
        $rows = $this->connection('mysql')->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseSafetyIncidentRepository::TABLE
        );

        return (int) $rows[0]->total;
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
    }
}
