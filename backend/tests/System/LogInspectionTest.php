<?php

declare(strict_types=1);

namespace Tests\System;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\EstablishSession;
use Cmp\Application\User\EstablishSessionCommand;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\SessionCarriage;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Tests\ConductSet;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * CMP-DOC-13 §19.1 **check 8** — log inspection under exercise.
 *
 * *"No credential, token, position or contact detail present."* `SEC-208` ‡ is
 * the statement; `SEC-209` ‡ adds that a diagnostic record excludes personal data
 * beyond what its purpose requires.
 *
 * **Level 5** (`TC-025`), and it has to be: `SEC-210` makes exclusion a property
 * of the call sites, which `LoggingRedactionRulesTest` checks statically — and a
 * static check cannot see what a value **is** at run time. A token reaches this
 * platform as `$token`, and the only way to know it never reaches a log is to put
 * a real one through a real request and read what came out.
 *
 * ## What is exercised
 *
 * Everything a session can do over HTTP, plus the refusals: establish a session in
 * the store, resolve it, terminate it, refresh it, present a terminated token,
 * present nonsense, and change a policy value. Between them these reach both of
 * the platform's two logging sites and every conduct `SEC-206` ‡ names that is
 * wired today.
 *
 * ## Why the log is captured to a file rather than to a spy
 *
 * A spy records what the platform **asked** to log. This reads what was
 * **written** — through the real formatter, with the real context serialisation.
 * `SEC-208` ‡ is about what ends up in the log, and a context array that stringifies
 * an object into it would pass a spy and fail here, which is the right way round.
 */
final class LogInspectionTest extends TestCase
{
    use ClearsTheEvidentialLog;

    private const REFERENCE = 'cccc1111dddd2222eeee3333ffff4444';

    private const PHONE = '+910000000208';

    private string $logPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = tempnam(sys_get_temp_dir(), 'cmp-log-').'.log';

        // A channel of this test's own, so the assertion reads what this
        // exercise produced rather than whatever else has run on this machine.
        Log::swap(new Logger('cmp-under-exercise', [new StreamHandler($this->logPath, Logger::DEBUG)]));

        $this->clearAll();
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test',
        );
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        if ($this->logPath !== '' && file_exists($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    public function test_no_forbidden_value_reaches_the_log_under_exercise(): void
    {
        // SEC-208 ‡ / check 8. The real token, the real hash and the real phone
        // number are searched for by value — not by name — because a leak does
        // not announce itself with a helpful key.
        [$token, $session] = $this->exercise();

        $log = $this->log();

        self::assertStringNotContainsString($token, $log, 'SEC-038 ‡: the session token reached the log.');
        self::assertStringNotContainsString(
            bin2hex($session->tokenHash()),
            $log,
            'SEC-038 ‡: the token hash reached the log. It is what the store matches on.',
        );
        self::assertStringNotContainsString($session->tokenHash(), $log);
        self::assertStringNotContainsString(self::PHONE, $log, 'BAD-RULE-043: the contact detail reached the log.');
    }

    public function test_no_forbidden_key_appears_in_the_log_under_exercise(): void
    {
        // The other direction. A value the test does not know to look for would
        // still arrive under a name, and SEC-208 ‡'s five categories are named
        // in ConductSet::forbiddenInALogLine().
        $this->exercise();

        $log = strtolower($this->log());
        $offenders = [];

        foreach (ConductSet::forbiddenInALogLine() as $forbidden) {
            // As a JSON key, which is how Monolog writes a context array.
            if (preg_match('/"'.preg_quote($forbidden, '/').'"\s*:/', $log) === 1) {
                $offenders[] = $forbidden;
            }
        }

        self::assertSame([], $offenders, 'SEC-208 ‡: a forbidden key was written under exercise.');
    }

    public function test_the_exercise_actually_wrote_to_the_log(): void
    {
        // Check 8 says "under exercise", and an empty log satisfies every
        // assertion above for the wrong reason. A refused authorisation is the
        // conduct that writes one — SEC-057 ‡ records every one, and
        // EvidentialAuthorisationRefusals logs it second.
        $this->exercise();

        self::assertNotSame('', trim($this->log()), 'The exercise logged nothing, so it inspected nothing.');
        self::assertStringContainsString('authorisation.refused', $this->log());
    }

    public function test_the_inspection_would_notice_a_token_in_the_log(): void
    {
        // TC-024 ‡: a detector nobody validated is one nobody can trust. A
        // deliberate write, then the same search — so the assertions above are
        // known to be capable of failing.
        [$token] = $this->exercise();

        self::assertStringNotContainsString($token, $this->log());

        Log::info('deliberate-leak-under-test', ['value' => $token]);

        self::assertStringContainsString(
            $token,
            $this->log(),
            'The inspection cannot see a token in the log, so its silence means nothing.',
        );
    }

    /**
     * Everything a session can do, and the refusals — through the real surface.
     *
     * @return array{0: string, 1: Session}
     */
    private function exercise(): array
    {
        [$token, $session] = $this->establish();

        // SEC-206 ‡'s first conduct — a refused authorisation — and the only one
        // of the eight that reaches a logging site today.
        //
        // It cannot be provoked over HTTP, and that is a fact about the platform
        // rather than about this test: every rule stated is
        // AuthorisationRule::requiringParty(), and SEC-044 ‡ binds a session to
        // exactly one actor — so whoever holds the token is always the party. A
        // request with a bad token is refused by RequireSession before
        // authorisation runs at all, producing a SessionRefusal and no
        // AuthorisationRefused.
        //
        // So it is provoked where a caller other than the party can exist: through
        // the application service, with a mismatched actor. Check 8 says "under
        // exercise", not "over HTTP", and SessionEstablishmentTest takes the same
        // path for the same reason.
        $this->app->make(EstablishSession::class)->execute(
            new EstablishSessionCommand(
                UserReference::fromString(self::REFERENCE),
                IdempotencyKey::fromString('log-inspection-cross-actor'),
            ),
            Actor::holding(ActorReference::fromString('1111222233334444555566667777888a'), []),
        );

        // And a bad token over HTTP, for the session-refusal path.
        $this->request('DELETE', 'sessions/current', 'not-a-real-token')->assertStatus(409);

        // Nonsense in the carriage header, which SEC-048 ‡ answers identically.
        $this->withHeaders([
            RequireIdempotencyKey::HEADER => 'log-inspection-malformed',
            SessionCarriage::REQUEST_HEADER => 'Basic '.base64_encode('user:'.self::PHONE),
        ])->json('DELETE', '/api/v1/sessions/current')->assertStatus(409);

        // The real operations, with a real token.
        $this->request('POST', 'sessions/current/refresh', $token);
        $this->request('DELETE', 'sessions/current', $token);

        // And the terminated token presented again — DB-044 ‡'s detectable reuse.
        $this->request('DELETE', 'sessions/current', $token)->assertStatus(409);

        // A read that carries the configuration, so the response path is
        // exercised too.
        $this->getJson('/api/v1/configuration')->assertOk();

        // SEC-206 ‡'s policy-change conduct.
        $this->app->make(ChangePolicyValue::class)->apply(
            PolicyServiceProvider::concurrentSessionLimit()->name(), '3', 'operator-under-test',
        );

        return [$token, $session];
    }

    /**
     * @return array{0: string, 1: Session}
     */
    private function establish(): array
    {
        $this->connection('mysql')->insert(
            'INSERT INTO op_users (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [
                self::REFERENCE, self::PHONE, 'VERIFIED', 'ACTIVE',
                '2026-08-21 12:00:00.000000', '2026-08-21 12:00:00.000000',
            ],
        );

        $tokens = $this->app->make(HashesSessionTokens::class);
        $token = $tokens->generate();
        $session = Session::establish(
            UserReference::fromString(self::REFERENCE),
            $tokens->hash($token),
            // The platform's clock, never a date literal: SEC-039 ‡ bounds a
            // session at twenty-four hours, so a pinned fixture becomes an
            // expired session the day after it is written — which is exactly
            // what happened to SessionEndpointTest on 2026-08-21.
            $this->app->make(Clock::class)->now(),
        );

        $this->app->make(SessionRepository::class)->save($session);

        return [$token, $session];
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function request(string $method, string $path, string $token): TestResponse
    {
        return $this->withHeaders([
            RequireIdempotencyKey::HEADER => 'log-inspection-'.$method.'-'.substr(hash('sha256', $token.$path), 0, 12),
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$token,
        ])->json($method, '/api/v1/'.$path);
    }

    private function log(): string
    {
        if (! file_exists($this->logPath)) {
            return '';
        }

        $contents = file_get_contents($this->logPath);

        self::assertIsString($contents);

        return $contents;
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
        $store = $this->app->make(DatabasePolicyStore::class);

        $migration->delete('DELETE FROM '.DatabaseSessionRepository::TABLE);
        $migration->delete('DELETE FROM op_user_credentials');
        $migration->delete('DELETE FROM op_users');

        foreach ([PolicyServiceProvider::sessionLifetime(), PolicyServiceProvider::concurrentSessionLimit()] as $key) {
            $migration->delete(
                'DELETE FROM '.DatabasePolicyStore::VERSIONS_TABLE.' WHERE policy_value_id IN'
                .' (SELECT id FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?)',
                [$key->name()],
            );
            $migration->delete(
                'DELETE FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
                [$key->name()],
            );

            $store->forget($key);
        }

        $this->clearEvidentialLog();
    }
}
