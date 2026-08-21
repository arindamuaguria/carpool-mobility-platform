<?php

declare(strict_types=1);

namespace Tests\Integration\User;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\Shared\Result;
use Cmp\Application\User\CurrentSessionCommand;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\RefreshCurrentSession;
use Cmp\Application\User\ResolveSession;
use Cmp\Application\User\SessionRefused;
use Cmp\Application\User\TerminateCurrentSession;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\Integration\IntegrationTestCase;

/**
 * `CMP-IMP-056`, `CMP-IMP-057` — refreshing and terminating a session.
 *
 * Level 3 (`TC-030` ‡). Both operations turn on things only a real store shows:
 * `DB-044` ‡'s retained termination row, `SEC-043`'s two writes in one
 * transaction, and `SEC-050`'s evidential record sharing that transaction under
 * `BE-106` ‡.
 *
 * The actor is constructed here rather than resolved, so that a failure at this
 * level is the operation's rather than the resolution's. `CC-032` settled how an
 * actor is resolved while `SEC-063` leaves the role set undecided —
 * `RegisteredActorRolesTest` covers that at level 2, and
 * `Tests\System\SessionEndpointTest` covers the two together over HTTP at level
 * 5. Both rules here turn on `SEC-066` ‡, being a party to the record, which
 * needs no role.
 */
final class SessionLifecycleTest extends IntegrationTestCase
{
    use ClearsTheEvidentialLog;

    private const REFERENCE = 'eeeeeeeeffffffff00000000111111ab';

    private const OTHER_REFERENCE = '99999999888888887777777766666655';

    private const PHONE = '+910000000056';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->applyLifetime();
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_terminating_records_the_session_as_terminated_and_keeps_the_row(): void
    {
        // FRD-FR-019 / SEC-040 ‡ / DB-044 ‡: recorded as terminated, not removed,
        // so that reuse is detectable rather than merely impossible.
        [$token, $session] = $this->establishFor(self::REFERENCE);

        $result = $this->terminate($session, self::REFERENCE);

        self::assertTrue($result->isSuccess());
        self::assertCount(1, $this->sessionRows());
        self::assertNotNull($this->sessionRows()[0]->terminated_at);
    }

    public function test_a_terminated_token_is_then_refused(): void
    {
        // The other half of FRD-FR-019 — "and prevent its reuse" — which is
        // ResolveSession's, and API-103 ‡ makes the refusal identical to one for
        // an unknown token.
        [$token, $session] = $this->establishFor(self::REFERENCE);
        $this->terminate($session, self::REFERENCE);

        $this->expectException(SessionRefused::class);

        $this->app->make(ResolveSession::class)->forToken($token);
    }

    public function test_terminating_twice_succeeds_and_changes_nothing(): void
    {
        // API-062 ‡: a repeat returns the original outcome and produces no second
        // effect. The retained instant is the first one, because DB-044 ‡'s
        // detection needs when the session stopped being usable.
        [, $session] = $this->establishFor(self::REFERENCE);

        self::assertTrue($this->terminate($session, self::REFERENCE)->isSuccess());
        $first = $this->sessionRows()[0]->terminated_at;

        self::assertTrue($this->terminate($session, self::REFERENCE)->isSuccess());

        self::assertSame($first, $this->sessionRows()[0]->terminated_at);
        self::assertCount(1, $this->sessionRows());
    }

    public function test_another_actor_cannot_terminate_a_session_they_do_not_hold(): void
    {
        // Negative test for SEC-066 ‡ / SEC-044 ‡. A session is bound to one
        // actor and is not transferable, and the party rule is what enforces it —
        // SEC-069 ‡ then gives the refusal the one caller-facing identifier.
        [, $session] = $this->establishFor(self::REFERENCE);

        $result = $this->terminate($session, self::OTHER_REFERENCE);

        self::assertTrue($result->isFailure());
        self::assertCount(1, $this->sessionRows());
        self::assertNull($this->sessionRows()[0]->terminated_at, 'The session must be untouched.');
    }

    public function test_another_actor_cannot_refresh_a_session_they_do_not_hold(): void
    {
        // `TC-116` ‡ / `SEC-236`: negative authorisation cases exist for **every**
        // operation, not a sample. Terminate had one and refresh did not, which
        // the §17.2 obligation register surfaced — a sample is exactly what two
        // out of three is.
        //
        // The rule is the same `requiringParty()` one, and that is the point: an
        // operation is not covered because the operation beside it is. `SEC-044` ‡
        // binds a session to one actor and makes it non-transferable, so a
        // caller who is not that actor is refused whichever verb they used.
        [$token, $session] = $this->establishFor(self::REFERENCE);

        $result = $this->refresh($session, self::OTHER_REFERENCE);

        self::assertTrue($result->isFailure());

        // And nothing happened: `SEC-043` would have terminated this session and
        // written a second, so one untouched row is the whole assertion.
        self::assertCount(1, $this->sessionRows());
        self::assertNull($this->sessionRows()[0]->terminated_at, 'The session must be untouched.');

        // The token the caller already held still resolves — a refused refresh
        // must not cost the rightful holder their session.
        self::assertFalse($this->app->make(ResolveSession::class)->forToken($token)->isTerminated());
    }

    public function test_refreshing_issues_a_new_token_and_invalidates_the_previous(): void
    {
        // SEC-043, both halves.
        [$token, $session] = $this->establishFor(self::REFERENCE);

        $result = $this->refresh($session, self::REFERENCE);

        self::assertTrue($result->isSuccess());
        $issued = $result->value();
        self::assertIsString($issued);
        self::assertNotSame($token, $issued);

        // The previous is terminated, not removed — DB-044 ‡ again.
        self::assertCount(2, $this->sessionRows());
        self::assertFalse($this->app->make(ResolveSession::class)->permits($token));
        self::assertTrue($this->app->make(ResolveSession::class)->permits($issued));
    }

    public function test_a_refreshed_session_starts_its_bound_again(): void
    {
        // NFR-055 extends within the bound, and a fresh establishment instant is
        // what gives the new session the whole of SEC-039 ‡'s twenty-four hours.
        [, $session] = $this->establishFor(self::REFERENCE);

        $this->refresh($session, self::REFERENCE);

        $rows = $this->sessionRows();
        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]->established_at, $rows[1]->established_at);
    }

    public function test_refreshing_does_not_increase_the_number_of_usable_sessions(): void
    {
        // Why SEC-049's limit is not consulted here: one session ends as one
        // begins, so a refresh cannot take a user past a limit they were within.
        // That matters because what happens AT the limit is not stated by any
        // requirement, and nothing here invents it.
        [$token, $session] = $this->establishFor(self::REFERENCE);
        $issued = $this->refresh($session, self::REFERENCE)->value();
        self::assertIsString($issued);

        $resolver = $this->app->make(ResolveSession::class);

        self::assertFalse($resolver->permits($token));
        self::assertTrue($resolver->permits($issued));
    }

    public function test_both_operations_are_evidenced(): void
    {
        // SEC-050: establishment, refresh and termination each evidenced. Two of
        // the three exist; establishment is CMP-IMP-053 and is blocked.
        [, $session] = $this->establishFor(self::REFERENCE);

        $this->refresh($session, self::REFERENCE);
        [, $second] = $this->establishFor(self::OTHER_REFERENCE, phoneSeed: self::OTHER_REFERENCE);
        $this->terminate($second, self::OTHER_REFERENCE);

        $actions = array_map(
            static fn (object $row): string => $row->action,
            $this->evidentialRows(),
        );

        self::assertContains(RefreshCurrentSession::ACTION, $actions);
        self::assertContains(TerminateCurrentSession::ACTION, $actions);
    }

    public function test_the_evidential_record_carries_no_token_and_no_hash(): void
    {
        // BE-201 ‡ / SEC-038 ‡: a token appears in the response that issues it and
        // nowhere else, and the hash is no better — it is what the store matches
        // on, so a log holding it holds the means to find the session.
        [$token, $session] = $this->establishFor(self::REFERENCE);
        $this->terminate($session, self::REFERENCE);

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($token, $encoded);
        self::assertStringNotContainsString(bin2hex($session->tokenHash()), $encoded);
    }

    private function terminate(Session $session, string $actorReference): Result
    {
        return $this->app->make(TerminateCurrentSession::class)->execute(
            new CurrentSessionCommand($session, IdempotencyKey::fromString('key-'.$actorReference)),
            Actor::holding(ActorReference::fromString($actorReference), []),
        );
    }

    private function refresh(Session $session, string $actorReference): Result
    {
        return $this->app->make(RefreshCurrentSession::class)->execute(
            new CurrentSessionCommand($session, IdempotencyKey::fromString('refresh-'.$actorReference)),
            Actor::holding(ActorReference::fromString($actorReference), []),
        );
    }

    /**
     * A user and a session for them, established **now** unless stated otherwise.
     *
     * `$at` reads from the platform's clock by default. A fixture pinned to a
     * date literal ages past `SEC-039` ‡'s twenty-four-hour bound the day after
     * it is written, and every test needing a usable session then fails for a
     * reason that has nothing to do with the platform. It did.
     *
     * @return array{0: string, 1: Session}
     */
    private function establishFor(string $reference, ?string $at = null, ?string $phoneSeed = null): array
    {
        $this->createUser($reference, self::PHONE.($phoneSeed === null ? '' : '9'));

        $tokens = $this->app->make(HashesSessionTokens::class);
        $token = $tokens->generate();
        $session = Session::establish(
            UserReference::fromString($reference),
            $tokens->hash($token),
            $at === null ? $this->app->make(Clock::class)->now() : Instant::fromString($at),
        );

        $this->app->make(SessionRepository::class)->save($session);

        return [$token, $session];
    }

    private function createUser(string $reference, string $phone): void
    {
        $existing = $this->applicationConnection()->select(
            'SELECT id FROM op_users WHERE external_id = ?', [$reference],
        );

        if ($existing !== []) {
            return;
        }

        $this->applicationConnection()->insert(
            'INSERT INTO op_users (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [$reference, $phone, 'VERIFIED', 'ACTIVE', '2026-08-20 12:00:00.000000', '2026-08-20 12:00:00.000000'],
        );
    }

    /**
     * @return list<object{established_at: string, terminated_at: ?string}>
     */
    private function sessionRows(): array
    {
        /** @var list<object{established_at: string, terminated_at: ?string}> $rows */
        $rows = $this->applicationConnection()->select(
            'SELECT established_at, terminated_at FROM '.DatabaseSessionRepository::TABLE.' ORDER BY id ASC'
        );

        return $rows;
    }

    /**
     * @return list<object{actor: string, action: string, subject: string}>
     */
    private function evidentialRows(): array
    {
        /** @var list<object{actor: string, action: string, subject: string}> $rows */
        $rows = $this->applicationConnection()->select(
            'SELECT actor, action, subject FROM '.DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
        );

        return $rows;
    }

    private function applyLifetime(): void
    {
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test',
        );
    }

    private function clearAll(): void
    {
        $migration = $this->migrationConnection();

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
