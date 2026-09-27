<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Application\User\SessionRefusal;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\SessionCarriage;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Tests\TestCase;

/**
 * `SEC-037` ‡ — *"A token shall be carried in a request header and never in a
 * URI, a query parameter or a body field."*
 *
 * **Level 4** (`TC-025`, `TC-031`): where a credential may travel is a property
 * of the contract.
 *
 * ## Why this was worth writing
 *
 * The platform already carries the token in `Authorization: Bearer` and reads it
 * from nowhere else. Nothing observed that. Accepting a token from
 * `?token=…` — or from a body field — is the single most common convenience
 * added to an API under deadline, and it would have passed every test in this
 * suite.
 *
 * `SEC-037` ‡ exists because a query parameter is not private: `SessionCarriage`
 * states it plainly — a URI reaches a server log, a proxy and a referrer, *"and a
 * query parameter reaches all three."* `NFR-062` and `API-100` are the sources.
 *
 * So the same **valid** token is offered three ways, and only the header one
 * authenticates. Using a valid token is the point: a refusal of an invalid one
 * would prove nothing about carriage.
 */
final class SessionCarriageTest extends TestCase
{
    private const USER = 'cab00001cab00002cab00003cab00004';

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test',
        );

        $this->insertUser();
        $this->token = $this->establish();
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_the_token_in_the_authorization_header_authenticates(): void
    {
        // The control. Without it the three refusals below would be consistent
        // with the token simply not working.
        $this->withHeaders([
            RequireIdempotencyKey::HEADER => 'carriage-header',
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$this->token,
        ])->json('GET', '/api/v1/profile/emergency-contacts')->assertOk();
    }

    public function test_the_same_token_in_a_query_parameter_does_not_authenticate(): void
    {
        // SEC-037 ‡. A query parameter reaches a server log, a proxy and a
        // referrer — SessionCarriage says so, and NFR-062 is why it matters.
        $this->withHeaders([RequireIdempotencyKey::HEADER => 'carriage-query'])
            ->json('GET', '/api/v1/profile/emergency-contacts?token='.$this->token)
            ->assertStatus(409)
            ->assertJsonPath('refusal.reason', SessionRefusal::NotUsable->value);
    }

    public function test_the_same_token_in_a_body_field_does_not_authenticate(): void
    {
        // SEC-037 ‡ names a body field explicitly. A POST is used because a body
        // on a GET is not something a client sends.
        $this->withHeaders([RequireIdempotencyKey::HEADER => 'carriage-body'])
            ->json('POST', '/api/v1/profile/emergency-contacts', [
                'token' => $this->token,
                'phone_number' => '+910000006001',
            ])
            ->assertStatus(409)
            ->assertJsonPath('refusal.reason', SessionRefusal::NotUsable->value);
    }

    public function test_a_token_offered_under_another_header_does_not_authenticate(): void
    {
        // The carriage is one header with one scheme. A second accepted spelling
        // would be a second door, and API-100 fixes the door.
        $this->withHeaders([
            RequireIdempotencyKey::HEADER => 'carriage-other-header',
            'X-Session-Token' => $this->token,
            SessionCarriage::ISSUE_HEADER => $this->token,
        ])->json('GET', '/api/v1/profile/emergency-contacts')
            ->assertStatus(409)
            ->assertJsonPath('refusal.reason', SessionRefusal::NotUsable->value);
    }

    public function test_the_refusal_says_nothing_about_where_the_token_was_found(): void
    {
        // SEC-048 ‡ / API-103 ‡: one refusal, whatever the cause. A message
        // distinguishing "wrong place" from "unknown token" would tell a caller
        // that the token itself was real.
        $misplaced = $this->withHeaders([RequireIdempotencyKey::HEADER => 'carriage-silent-a'])
            ->json('GET', '/api/v1/profile/emergency-contacts?token='.$this->token);

        $absent = $this->withHeaders([RequireIdempotencyKey::HEADER => 'carriage-silent-b'])
            ->json('GET', '/api/v1/profile/emergency-contacts');

        // The refusal itself, not the whole envelope: `API-043` ‡ stamps every
        // response with the instant it was evaluated, so two bodies differ by
        // that alone and comparing them would assert the clock.
        self::assertSame(
            $absent->json('refusal'),
            $misplaced->json('refusal'),
            'SEC-048 ‡: the two refusals are indistinguishable.',
        );

        // SEC-038 ‡: and neither echoes the token back.
        self::assertStringNotContainsString($this->token, (string) $misplaced->getContent());
    }

    private function establish(): string
    {
        $tokens = $this->app->make(HashesSessionTokens::class);
        $token = $tokens->generate();

        $this->app->make(SessionRepository::class)->save(Session::establish(
            UserReference::fromString(self::USER),
            $tokens->hash($token),
            $this->app->make(Clock::class)->now(),
        ));

        return $token;
    }

    private function insertUser(): void
    {
        $this->connection('mysql')->insert(
            'INSERT INTO '.DatabaseUserRepository::TABLE
            .' (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [
                self::USER, '+910000006000', 'VERIFIED', 'ACTIVE',
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

        $migration->delete('DELETE FROM '.DatabaseSessionRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseUserRepository::TABLE);
    }
}
