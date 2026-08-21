<?php

declare(strict_types=1);

namespace Tests\Integration\User;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Application\User\SessionRefusalCause;
use Cmp\Application\User\SessionRefused;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\User\RecordedSessionAnomalies;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\Integration\IntegrationTestCase;

/**
 * `SEC-206` ‡'s session anomaly — the fifth conduct, and where each half goes.
 *
 * **Level 3** (`TC-030` ‡): the record is evidential for two of the three causes,
 * and whether an evidential record was written is only answerable against the
 * store.
 *
 * `SEC-203` ‡ puts an actor's conduct in the evidential log and `SEC-204` puts the
 * platform's own health in the operational one. A token whose owner the platform
 * knows is the first; a token that names nobody is the second. That split is the
 * whole subject of this file.
 */
final class SessionAnomalyRecordingTest extends IntegrationTestCase
{
    use ClearsTheEvidentialLog;

    private const REFERENCE = 'dddd4444eeee5555ffff6666aaaa7777';

    private const PHONE = '+910000000206';

    private const LIFETIME = 86400;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, (string) self::LIFETIME, 'operator-under-test',
        );

        // BE-173 evidenced that change. The log goes back to empty so that what
        // is asserted below is this operation's record and not the fixture's.
        $this->clearEvidentialLog();
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_terminated_token_presented_again_is_recorded_against_its_owner(): void
    {
        // DB-044 ‡ retains the row "so that reuse is detectable rather than
        // merely impossible" — this is the detection, and SEC-203 ‡ makes it
        // conduct because the platform knows whose session it was.
        $token = $this->establish();
        $session = $this->app->make(ResolveSession::class)->forToken($token);
        $session->terminate($this->clock()->now());
        $this->app->make(SessionRepository::class)->save($session);
        $this->clearEvidentialLog();

        $this->expectRefusal($token);

        $rows = $this->evidentialRows();

        self::assertCount(1, $rows);
        self::assertSame(RecordedSessionAnomalies::ACTION, $rows[0]->action);
        self::assertSame(self::REFERENCE, $rows[0]->actor);
        self::assertSame(self::REFERENCE, $rows[0]->subject);
        // The internal cause, which SEC-048 ‡ keeps from the caller and an
        // operator needs. Asserted against the enum rather than against a word
        // this test guessed at.
        self::assertSame(SessionRefusalCause::Terminated->describe(), $rows[0]->reason);
    }

    public function test_an_expired_token_is_recorded_against_its_owner(): void
    {
        // SEC-039 ‡'s bound, reached. The owner is known for the same reason —
        // the row is there — so this is conduct too.
        $tokens = $this->app->make(HashesSessionTokens::class);
        $token = $tokens->generate();

        $this->createUser();
        $this->app->make(SessionRepository::class)->save(Session::establish(
            UserReference::fromString(self::REFERENCE),
            $tokens->hash($token),
            Instant::fromString('2020-01-01T00:00:00Z'),
        ));

        $this->expectRefusal($token);

        $rows = $this->evidentialRows();

        self::assertCount(1, $rows);
        self::assertSame(self::REFERENCE, $rows[0]->actor);
        self::assertSame(SessionRefusalCause::Expired->describe(), $rows[0]->reason);
    }

    public function test_an_unknown_token_writes_no_evidential_record(): void
    {
        // SEC-204 / BE-107 ‡. An unknown token names nobody, and an evidential
        // record that cannot say what it is about is not evidence — inventing an
        // actor to fill the field would invent the very thing the record states.
        //
        // The negative is the assertion: the operational half is asserted at
        // level 5 by LogInspectionTest, which reads what was actually written.
        $this->createUser();

        $this->expectRefusal('a-token-nobody-ever-issued');

        self::assertSame([], $this->evidentialRows(), 'SEC-204: nobody\'s conduct writes nobody\'s record.');
    }

    public function test_no_record_carries_the_token_or_its_hash(): void
    {
        // SEC-208 ‡ / SEC-038 ‡. The hash is what the store matches on, so a
        // record holding it holds the means to find the session — SEC-210 keeps
        // both out by construction, and RecordsSessionAnomalies has no parameter
        // to pass one in.
        $token = $this->establish();
        $session = $this->app->make(ResolveSession::class)->forToken($token);
        $session->terminate($this->clock()->now());
        $this->app->make(SessionRepository::class)->save($session);
        $this->clearEvidentialLog();

        $this->expectRefusal($token);

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($token, $encoded);
        self::assertStringNotContainsString(bin2hex($session->tokenHash()), $encoded);
        self::assertStringNotContainsString(self::PHONE, $encoded);
    }

    public function test_a_usable_token_records_nothing(): void
    {
        // The record exists because something happened. A resolution that
        // succeeded is not an anomaly, and a platform that recorded one on every
        // request would drown the log it wrote to be read.
        $token = $this->establish();

        $this->clearEvidentialLog();
        $this->app->make(ResolveSession::class)->forToken($token);

        self::assertSame([], $this->evidentialRows());
    }

    private function expectRefusal(string $token): void
    {
        try {
            $this->app->make(ResolveSession::class)->forToken($token);
            self::fail('SEC-048 ‡: an unusable token is refused.');
        } catch (SessionRefused) {
            // The refusal is the point; SEC-048 ‡ makes all three look alike to a
            // caller, and what differs is the record.
        }
    }

    private function establish(): string
    {
        $this->createUser();

        $tokens = $this->app->make(HashesSessionTokens::class);
        $token = $tokens->generate();

        $this->app->make(SessionRepository::class)->save(Session::establish(
            UserReference::fromString(self::REFERENCE),
            $tokens->hash($token),
            $this->clock()->now(),
        ));

        return $token;
    }

    private function createUser(): void
    {
        $existing = $this->applicationConnection()->select(
            'SELECT id FROM op_users WHERE external_id = ?', [self::REFERENCE],
        );

        if ($existing !== []) {
            return;
        }

        $now = $this->clock()->now()->toDatabaseString();

        $this->applicationConnection()->insert(
            'INSERT INTO op_users (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [self::REFERENCE, self::PHONE, 'VERIFIED', 'ACTIVE', $now, $now],
        );
    }

    private function clock(): Clock
    {
        $clock = $this->app->make(Clock::class);

        self::assertInstanceOf(Clock::class, $clock);

        return $clock;
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
