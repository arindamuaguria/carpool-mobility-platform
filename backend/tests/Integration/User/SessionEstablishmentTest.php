<?php

declare(strict_types=1);

namespace Tests\Integration\User;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\Shared\Result;
use Cmp\Application\User\EstablishmentRefusal;
use Cmp\Application\User\EstablishSession;
use Cmp\Application\User\EstablishSessionCommand;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
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
 * `CMP-IMP-053` — establishing a session, and the three refusals.
 *
 * **Level 3** (`TC-030` ‡): a real MySQL, because what this adds to level 2 is the
 * part only a store can show — that `SEC-243` ‡'s count excludes terminated rows
 * (`DB-044` ‡ keeps them forever) and expired ones (`SEC-039` ‡ retires them), and
 * that `SEC-050`'s record is written in the transaction carrying the effect.
 *
 * `FR-04` requires a negative test for every ‡ requirement, and two land here:
 * `SEC-051` ‡ (a restricted account gets no session) and `SEC-243` ‡ (a user at
 * the limit is refused, and nothing is evicted).
 */
final class SessionEstablishmentTest extends IntegrationTestCase
{
    use ClearsTheEvidentialLog;

    private const REFERENCE = 'aaaa1111bbbb2222cccc3333dddd4444';

    private const PHONE = '+910000000053';

    private const LIFETIME = 86400;

    private const LIMIT = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->applyPolicy(self::LIFETIME, self::LIMIT);

        // BE-173 evidences every policy change, and applyPolicy() made two. They
        // are cleared here rather than in clearAll() so that a test asserting
        // what the log holds is reading this operation's records and not the
        // fixture's.
        $this->clearEvidentialLog();
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_verified_active_user_receives_a_session(): void
    {
        // FRD-FR-016: a session is established on successful authentication.
        // SEC-035 ‡ / SEC-036 ‡: the token comes back once, and the store holds
        // only its hash.
        $this->createUser('VERIFIED', 'ACTIVE');

        $result = $this->establish();

        self::assertTrue($result->isSuccess());

        $token = $result->value();

        self::assertIsString($token);
        self::assertNotSame('', $token);

        // SEC-042: the token resolves, which is the end-to-end fact a caller
        // cares about.
        $session = $this->app->make(ResolveSession::class)->forToken($token);

        self::assertSame(self::REFERENCE, $session->user()->toString());
        self::assertSame(1, $this->usableCount());
    }

    public function test_the_stored_row_holds_no_token(): void
    {
        // SEC-036 ‡ / SEC-038 ‡. Asserted against what is actually in the column,
        // not against what the repository was asked to write.
        $this->createUser('VERIFIED', 'ACTIVE');

        $token = $this->establish()->value();

        self::assertIsString($token);

        /** @var list<object{token_hash: string}> $rows */
        $rows = $this->applicationConnection()->select(
            'SELECT token_hash FROM '.DatabaseSessionRepository::TABLE
        );

        self::assertCount(1, $rows);
        self::assertStringNotContainsString($token, $rows[0]->token_hash);
    }

    public function test_an_unverified_user_is_routed_to_verification(): void
    {
        // FRD-FR-018 / UC-003 E3: routed to phone verification rather than into
        // the application. Its own identifier, because a client cannot route on
        // a refusal it cannot tell apart from the others.
        $this->createUser('UNVERIFIED', 'ACTIVE');

        $this->assertRefusedWith(EstablishmentRefusal::VerificationRequired, $this->establish());
        self::assertSame(0, $this->usableCount());
    }

    public function test_a_suspended_account_receives_no_session(): void
    {
        // Negative test for SEC-051 ‡: "No session shall be established for a
        // caller whose account state does not permit it." BAD-RULE-010 is
        // absolute, and UC-003 E2 states the refusal without an appeal path —
        // BAD-DEC-006 and BAD-DEC-016 leave that undecided.
        $this->createUser('VERIFIED', 'SUSPENDED');

        $this->assertRefusedWith(EstablishmentRefusal::AccountRestricted, $this->establish());
        self::assertSame(0, $this->usableCount());
    }

    public function test_a_deactivated_account_receives_no_session(): void
    {
        // The other half of SEC-051 ‡'s negative case. Both non-Active states,
        // because AccountState::permitsAuthenticatedUse() is written as an
        // equality and a regression would show in exactly one of the two.
        $this->createUser('VERIFIED', 'DEACTIVATED');

        $this->assertRefusedWith(EstablishmentRefusal::AccountRestricted, $this->establish());
        self::assertSame(0, $this->usableCount());
    }

    public function test_a_restricted_account_is_refused_before_it_is_told_to_verify(): void
    {
        // The order the service states: state before standing. Sending a
        // suspended user to verify their number would have them complete a flow
        // that still ends in refusal.
        $this->createUser('UNVERIFIED', 'SUSPENDED');

        $this->assertRefusedWith(EstablishmentRefusal::AccountRestricted, $this->establish());
    }

    public function test_a_user_at_the_limit_is_refused_and_nothing_is_evicted(): void
    {
        // Negative test for SEC-243 ‡, and the whole of it: "establishment shall
        // be refused ... No existing session shall be terminated to make room."
        $this->createUser('VERIFIED', 'ACTIVE');

        $held = [];

        for ($i = 0; $i < self::LIMIT; $i++) {
            $result = $this->establish('key-'.$i);
            self::assertTrue($result->isSuccess());
            $token = $result->value();
            self::assertIsString($token);
            $held[] = $token;
        }

        $this->assertRefusedWith(EstablishmentRefusal::ConcurrentLimitReached, $this->establish('key-fourth'));

        // Nothing was evicted: the three still resolve, and no row was
        // terminated.
        self::assertSame(self::LIMIT, $this->usableCount());

        foreach ($held as $token) {
            self::assertFalse($this->app->make(ResolveSession::class)->forToken($token)->isTerminated());
        }

        self::assertSame(0, $this->terminatedCount());
    }

    public function test_terminating_a_session_makes_room_for_another(): void
    {
        // The consequence SEC-243 ‡'s FACT records: the user is not stuck, they
        // are told. DB-044 ‡ keeps the terminated row forever, so this also
        // proves the count is of usable sessions rather than of rows.
        $this->createUser('VERIFIED', 'ACTIVE');

        $tokens = [];

        for ($i = 0; $i < self::LIMIT; $i++) {
            $token = $this->establish('key-'.$i)->value();
            self::assertIsString($token);
            $tokens[] = $token;
        }

        $this->terminate($tokens[0]);

        self::assertTrue($this->establish('key-after-terminate')->isSuccess());

        // Four rows, three usable — the terminated one is still there.
        self::assertCount(4, $this->sessionRows());
        self::assertSame(self::LIMIT, $this->usableCount());
    }

    public function test_an_expired_session_does_not_count_toward_the_limit(): void
    {
        // SEC-039 ‡ retires a session by elapsed time and CMP-IMP-051 stores no
        // expires_at, so the count has to apply the bound rather than read a
        // column. A user whose three sessions all expired holds none.
        $this->createUser('VERIFIED', 'ACTIVE');

        $tokens = $this->app->make(HashesSessionTokens::class);
        $repository = $this->app->make(SessionRepository::class);
        $longAgo = Instant::fromString('2020-01-01T00:00:00Z');

        for ($i = 0; $i < self::LIMIT; $i++) {
            $repository->save(Session::establish(
                UserReference::fromString(self::REFERENCE),
                $tokens->hash($tokens->generate()),
                $longAgo,
            ));
        }

        self::assertCount(self::LIMIT, $this->sessionRows());
        self::assertSame(0, $this->usableCount());
        self::assertTrue($this->establish()->isSuccess());
    }

    public function test_a_caller_cannot_establish_a_session_for_somebody_else(): void
    {
        // SEC-066 ‡ / SEC-044 ‡, through the one authorisation evaluation
        // SADR-06 requires. The rule refuses it, not a check inside the service —
        // and SEC-069 ‡ gives the refusal the one caller-facing identifier.
        $this->createUser('VERIFIED', 'ACTIVE');

        $result = $this->app->make(EstablishSession::class)->execute(
            new EstablishSessionCommand(
                UserReference::fromString(self::REFERENCE),
                IdempotencyKey::fromString('key-cross-actor'),
            ),
            Actor::holding(ActorReference::fromString('99998888777766665555444433332211'), []),
        );

        self::assertTrue($result->isFailure());
        self::assertSame(0, $this->usableCount());
    }

    public function test_an_establishment_is_evidenced(): void
    {
        // SEC-050 / BE-106 ‡: evidenced in the transaction that carries the
        // effect, so an establishment that could not be evidenced does not stand.
        $this->createUser('VERIFIED', 'ACTIVE');

        $token = $this->establish()->value();

        self::assertIsString($token);

        $rows = $this->evidentialRows();

        self::assertCount(1, $rows);
        self::assertSame(EstablishSession::ACTION, $rows[0]->action);
        self::assertSame(self::REFERENCE, $rows[0]->subject);
    }

    public function test_a_refusal_is_evidenced_and_carries_its_reason(): void
    {
        // SEC-243 ‡ requires the refusal to be recorded, in terms. The other two
        // are recorded on the same path — an operator investigating why somebody
        // cannot sign in needs all three, and BE-202 forbids an operational log
        // standing in for the evidential record.
        $this->createUser('UNVERIFIED', 'ACTIVE');

        $this->establish();

        $rows = $this->evidentialRows();

        self::assertCount(1, $rows);
        self::assertSame(EstablishSession::REFUSED_ACTION, $rows[0]->action);
        self::assertSame(self::REFERENCE, $rows[0]->subject);
        self::assertSame(EstablishmentRefusal::VerificationRequired->identifier(), $rows[0]->reason);
    }

    public function test_no_evidential_record_carries_the_token_the_phone_number_or_a_hash(): void
    {
        // BE-201 ‡ / SEC-038 ‡. The phone number is included in the check because
        // BAD-RULE-043 makes it the account's identifying detail — which is
        // exactly why an evidential record naming the reference must not also
        // name it.
        $this->createUser('VERIFIED', 'ACTIVE');

        $token = $this->establish()->value();

        self::assertIsString($token);

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($token, $encoded);
        self::assertStringNotContainsString(self::PHONE, $encoded);
        self::assertStringNotContainsString(
            bin2hex($this->app->make(HashesSessionTokens::class)->hash($token)),
            $encoded,
        );
    }

    private function assertRefusedWith(EstablishmentRefusal $expected, Result $result): void
    {
        self::assertTrue($result->isFailure());

        $failure = $result->failure();

        self::assertInstanceOf(BusinessRefused::class, $failure);
        self::assertSame($expected->identifier(), $failure->identifier());

        // API-087: all three describe a state that can change, so a state
        // conflict rather than a rule declining.
        self::assertTrue($failure->isStateConflict());
    }

    private function establish(string $key = 'key-establish'): Result
    {
        return $this->app->make(EstablishSession::class)->execute(
            new EstablishSessionCommand(
                UserReference::fromString(self::REFERENCE),
                IdempotencyKey::fromString($key),
            ),
            Actor::holding(ActorReference::fromString(self::REFERENCE), []),
        );
    }

    private function terminate(string $token): void
    {
        $session = $this->app->make(ResolveSession::class)->forToken($token);
        $session->terminate($this->app->make(Clock::class)->now());

        $this->app->make(SessionRepository::class)->save($session);
    }

    private function usableCount(): int
    {
        return $this->app->make(SessionRepository::class)->usableCountFor(
            UserReference::fromString(self::REFERENCE),
            $this->app->make(Clock::class)->now(),
            self::LIFETIME,
        );
    }

    private function createUser(string $standing, string $state): void
    {
        $this->applicationConnection()->insert(
            'INSERT INTO op_users (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [
                self::REFERENCE, self::PHONE, $standing, $state,
                '2026-08-20 12:00:00.000000', '2026-08-20 12:00:00.000000',
            ],
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

    private function terminatedCount(): int
    {
        $terminated = 0;

        foreach ($this->sessionRows() as $row) {
            if ($row->terminated_at !== null) {
                $terminated++;
            }
        }

        return $terminated;
    }

    /**
     * @return list<object{actor: string, action: string, subject: string, reason: ?string}>
     */
    private function evidentialRows(): array
    {
        /** @var list<object{actor: string, action: string, subject: string, reason: ?string}> $rows */
        $rows = $this->applicationConnection()->select(
            'SELECT actor, action, subject, reason FROM '.DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
        );

        return $rows;
    }

    private function applyPolicy(int $lifetime, int $limit): void
    {
        $operator = $this->app->make(ChangePolicyValue::class);

        $operator->apply(ResolveSession::LIFETIME_KEY, (string) $lifetime, 'operator-under-test');
        $operator->apply(
            PolicyServiceProvider::concurrentSessionLimit()->name(),
            (string) $limit,
            'operator-under-test',
        );
    }

    private function clearAll(): void
    {
        $migration = $this->migrationConnection();
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
