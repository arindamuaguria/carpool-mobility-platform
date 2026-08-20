<?php

declare(strict_types=1);

namespace Tests\System;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Application\User\SessionRefusal;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\SessionCarriage;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * `CMP-IMP-056`, `CMP-IMP-057` — the two session operations, end to end.
 *
 * **Level 5** (`TC-025`), and the first test at this level. Level 5 is *system
 * behaviour*: a real HTTP request, through the real middleware, the real
 * application service and a real MySQL, answered by the real response. Nothing
 * here is substituted, because everything each earlier level had to substitute is
 * exactly what this level exists to exercise.
 *
 * Level 3 already proved that terminating retains the row (`DB-044` ‡) and that
 * refresh writes both halves in one transaction (`SEC-043`). Level 4 proved the
 * shape of the refusal. What is left, and what only this level can say, is that
 * the specified behaviour reaches a caller: `FRD-FR-019`'s termination, and
 * `SEC-043`'s new token arriving somewhere the client can read it.
 *
 * ## Why the actor is never sent
 *
 * No request here names a user. `SEC-045` ‡ forbids a session carrying an
 * authorisation claim, so the acting identity is resolved from the session row
 * and there is nothing in the request that could assert one. That is asserted
 * rather than assumed — {@see test_the_acting_identity_comes_from_the_session_and_not_the_request()}.
 */
final class SessionEndpointTest extends TestCase
{
    use ClearsTheEvidentialLog;

    private const REFERENCE = '11112222333344445555666677778888';

    private const OTHER_REFERENCE = '8888777766665555444433332222111a';

    private const PHONE = '+910000000156';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test',
        );
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_caller_terminates_the_session_they_hold(): void
    {
        // FRD-FR-019, over the wire. The response says what happened and carries
        // nothing else; FRD-FR-020's cache clearing is the client's, and MOB-144
        // clears the session material with it.
        [$token] = $this->establish(self::REFERENCE);

        $response = $this->request('DELETE', 'sessions/current', $token);

        $response->assertOk();
        $response->assertJsonPath('data.terminated', true);
        $response->assertJsonPath('meta.interface_version', 1);
    }

    public function test_a_terminated_token_then_stops_working_over_http(): void
    {
        // The other half of FRD-FR-019 — "and prevent its reuse" — reaching a
        // caller. API-103 ‡ / SEC-048 ‡: the refusal is the one an unknown token
        // gets, so a replayed token teaches an attacker nothing.
        [$token] = $this->establish(self::REFERENCE);

        $this->request('DELETE', 'sessions/current', $token)->assertOk();

        $again = $this->request('DELETE', 'sessions/current', $token);

        $again->assertStatus(409);
        $again->assertJsonPath('refusal.reason', SessionRefusal::NotUsable->value);
    }

    public function test_refresh_issues_a_new_token_in_a_header_and_not_in_the_body(): void
    {
        // SEC-043 and SEC-038 ‡ together. The token has to reach the client, and
        // it may not be in a response body — so it is in a header, and the body
        // says only that a refresh happened. API-064 keeps that distinguishable
        // from a replay.
        [$token] = $this->establish(self::REFERENCE);

        $response = $this->request('POST', 'sessions/current/refresh', $token);

        $response->assertOk();
        $response->assertJsonPath('data.refreshed', true);

        $issued = $response->headers->get(SessionCarriage::ISSUE_HEADER);

        self::assertIsString($issued);
        self::assertNotSame($token, $issued);
        self::assertStringNotContainsString($issued, (string) $response->getContent());
    }

    public function test_the_new_token_works_and_the_previous_one_does_not(): void
    {
        // SEC-043: "issue a new token and invalidate the previous one." Both
        // halves, as a caller experiences them.
        [$token] = $this->establish(self::REFERENCE);

        $issued = $this->request('POST', 'sessions/current/refresh', $token)
            ->headers->get(SessionCarriage::ISSUE_HEADER);

        self::assertIsString($issued);

        $this->request('DELETE', 'sessions/current', $issued)->assertOk();
        $this->request('DELETE', 'sessions/current', $token)->assertStatus(409);
    }

    public function test_the_acting_identity_comes_from_the_session_and_not_the_request(): void
    {
        // SEC-045 ‡ / SEC-044 ‡. Two users hold two sessions. A request bearing
        // one token, with the other user's reference put everywhere a caller
        // could put it, terminates the session the token names — and leaves the
        // other alone. There is no field in which an identity could be asserted,
        // and this is what proves there is not.
        [$mine] = $this->establish(self::REFERENCE);
        [$theirs] = $this->establish(self::OTHER_REFERENCE, '9');

        $this->withHeaders([
            RequireIdempotencyKey::HEADER => 'system-cross-actor',
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$mine,
            'X-User' => self::OTHER_REFERENCE,
        ])->json('DELETE', '/api/v1/sessions/current', ['user' => self::OTHER_REFERENCE])->assertOk();

        // Theirs is untouched, and still works.
        $this->request('DELETE', 'sessions/current', $theirs)->assertOk();

        self::assertSame(2, $this->terminatedCount());
    }

    public function test_neither_operation_puts_a_token_or_a_hash_in_the_evidential_record(): void
    {
        // BE-201 ‡ / SEC-038 ‡, asserted where the record is actually written
        // rather than where the writer is called. SEC-050 requires both
        // operations to be evidenced; the record names the actor and the action.
        [$token, $session] = $this->establish(self::REFERENCE);

        $this->request('POST', 'sessions/current/refresh', $token)->assertOk();

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('session.refreshed', $encoded);
        self::assertStringNotContainsString($token, $encoded);
        self::assertStringNotContainsString(bin2hex($session->tokenHash()), $encoded);
    }

    /**
     * One request, carrying what every authenticated state-changing operation
     * carries and nothing more.
     *
     * @return TestResponse<JsonResponse>
     */
    private function request(string $method, string $path, string $token): TestResponse
    {
        return $this->withHeaders([
            // API-057 ‡: state-changing, so it carries a key. Unique per call,
            // because API-062 ‡ would otherwise replay the first outcome and
            // these tests are about the second request doing something new.
            RequireIdempotencyKey::HEADER => 'system-'.$method.'-'.substr(hash('sha256', $token.$path), 0, 16),
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$token,
        ])->json($method, '/api/v1/'.$path);
    }

    /**
     * A user and a session for them, as the store would hold both.
     *
     * @return array{0: string, 1: Session}
     */
    private function establish(string $reference, string $phoneSuffix = ''): array
    {
        $this->connection('mysql')->insert(
            'INSERT INTO op_users (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [
                $reference, self::PHONE.$phoneSuffix, 'VERIFIED', 'ACTIVE',
                '2026-08-20 12:00:00.000000', '2026-08-20 12:00:00.000000',
            ],
        );

        $tokens = $this->app->make(HashesSessionTokens::class);
        $token = $tokens->generate();
        $session = Session::establish(
            UserReference::fromString($reference),
            $tokens->hash($token),
            Instant::fromString('2026-08-20T12:00:00Z'),
        );

        $this->app->make(SessionRepository::class)->save($session);

        return [$token, $session];
    }

    private function terminatedCount(): int
    {
        $rows = $this->connection('mysql')->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseSessionRepository::TABLE.' WHERE terminated_at IS NOT NULL'
        );

        self::assertArrayHasKey(0, $rows);
        /** @var object{total: int|string} $row */
        $row = $rows[0];

        return (int) $row->total;
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

    private function connection(string $name): Connection
    {
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection($name);

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function clearAll(): void
    {
        // DB-215: the only account holding DDL, and the only one that may delete
        // from op_ here.
        $migration = $this->connection('mysql_migration');

        $migration->delete('DELETE FROM '.DatabaseSessionRepository::TABLE);
        $migration->delete('DELETE FROM op_user_credentials');
        $migration->delete('DELETE FROM op_users');
        $migration->delete(
            'DELETE FROM '.DatabasePolicyStore::VERSIONS_TABLE.' WHERE policy_value_id IN'
            .' (SELECT id FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?)',
            [ResolveSession::LIFETIME_KEY],
        );
        $migration->delete(
            'DELETE FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
            [ResolveSession::LIFETIME_KEY],
        );

        $this->app->make(DatabasePolicyStore::class)->forget(PolicyServiceProvider::sessionLifetime());
        $this->clearEvidentialLog();
    }
}
