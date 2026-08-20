<?php

declare(strict_types=1);

namespace Tests\Integration\User;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Application\User\SessionRefusalCause;
use Cmp\Application\User\SessionRefused;
use Cmp\Domain\Shared\Policy\PolicyNotSet;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Tests\Integration\IntegrationTestCase;

/**
 * `CMP-IMP-055` — admitting a user who holds a valid existing session.
 *
 * Level 3 (`TC-030` ‡). `SEC-041` ‡ holds session state in the store rather than
 * in instance memory and `SEC-042` makes validation a hash-and-lookup against
 * it, so neither is provable without one. The policy read is real too: `SEC-039`
 * ‡'s bound comes from `cfg_policy_values`, and the case that matters most —
 * what the platform does when nobody has configured it — only happens against a
 * real store.
 */
final class SessionResolutionTest extends IntegrationTestCase
{
    private const REFERENCE = 'aaaaaaaabbbbbbbbccccccccdddddddd';

    private const PHONE = '+910000000055';

    /** Twenty-four hours, decided 2026-08-20. */
    private const LIFETIME_SECONDS = '86400';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_valid_token_admits_its_user_without_re_authentication(): void
    {
        // FRD-FR-017, and the whole point of the operation.
        $this->applyLifetime();
        $token = $this->establishSessionFor($this->createUser(), '2026-08-20T12:00:00Z');

        $session = $this->resolver()->forToken($token);

        self::assertSame(self::REFERENCE, $session->user()->toString());
        self::assertFalse($session->isTerminated());
    }

    public function test_an_unknown_token_is_refused(): void
    {
        // SEC-048 ‡, first of three.
        $this->applyLifetime();

        try {
            $this->resolver()->forToken('a-token-that-was-never-issued');
            self::fail('SEC-048 ‡: an unknown token must be refused.');
        } catch (SessionRefused $refused) {
            self::assertSame(SessionRefusalCause::Unknown, $refused->cause());
        }
    }

    public function test_a_terminated_token_is_refused(): void
    {
        // SEC-048 ‡, second of three, and DB-044 ‡ is what makes it detectable:
        // the record is retained, so the platform can tell a terminated token
        // from an unknown one even though the caller cannot.
        $this->applyLifetime();
        $token = $this->establishSessionFor($this->createUser(), '2026-08-20T12:00:00Z');

        $session = $this->sessions()->forTokenHash($this->tokens()->hash($token));
        self::assertNotNull($session);
        $session->terminate(Instant::fromString('2026-08-20T13:00:00Z'));
        $this->sessions()->save($session);

        try {
            $this->resolver()->forToken($token);
            self::fail('SEC-048 ‡: a terminated token must be refused.');
        } catch (SessionRefused $refused) {
            self::assertSame(SessionRefusalCause::Terminated, $refused->cause());
        }
    }

    public function test_an_expired_token_is_refused(): void
    {
        // SEC-048 ‡, third of three. Established two days ago against a
        // twenty-four hour bound.
        $this->applyLifetime();
        $token = $this->establishSessionFor($this->createUser(), '2020-01-01T00:00:00Z');

        try {
            $this->resolver()->forToken($token);
            self::fail('SEC-039 ‡ / SEC-048 ‡: an expired token must be refused.');
        } catch (SessionRefused $refused) {
            self::assertSame(SessionRefusalCause::Expired, $refused->cause());
        }
    }

    public function test_the_three_refusals_are_indistinguishable_to_a_caller(): void
    {
        // The half of SEC-048 ‡ that matters. The internal causes above differ;
        // what reaches a caller must not, or a caller learns that a token was
        // once real.
        $this->applyLifetime();
        $user = $this->createUser();

        $expired = $this->establishSessionFor($user, '2020-01-01T00:00:00Z');
        $terminatedToken = $this->establishSessionFor($user, '2026-08-20T12:00:00Z', 'second');
        $session = $this->sessions()->forTokenHash($this->tokens()->hash($terminatedToken));
        self::assertNotNull($session);
        $session->terminate(Instant::fromString('2026-08-20T13:00:00Z'));
        $this->sessions()->save($session);

        $reasons = [];

        foreach ([$expired, $terminatedToken, 'never-issued'] as $token) {
            try {
                $this->resolver()->forToken($token);
                self::fail('Each of the three must be refused.');
            } catch (SessionRefused $refused) {
                $reasons[] = [
                    $refused->reason()->identifier(),
                    $refused->reason()->defaultText(),
                    $refused->getMessage(),
                ];
            }
        }

        self::assertCount(3, $reasons);
        self::assertCount(1, array_unique(array_map(
            static fn (array $reason): string => implode('|', $reason),
            $reasons,
        )), 'SEC-048 ‡: terminated, expired and unknown are indistinguishable to a caller.');
    }

    public function test_an_unconfigured_lifetime_refuses_rather_than_defaulting(): void
    {
        // SRS-REQ-158 rejects an unconfigured value and SRS-REQ-113 forbids
        // synthesising one. A platform that picked its own session lifetime would
        // be choosing a security policy nobody decided — so it fails loudly
        // instead, and the failure is a fault rather than a refusal because
        // nothing about the caller is wrong.
        $token = $this->establishSessionFor($this->createUser(), '2026-08-20T12:00:00Z');

        $this->expectException(PolicyNotSet::class);

        $this->resolver()->forToken($token);
    }

    public function test_the_stored_form_is_a_hash_and_not_the_token(): void
    {
        // SEC-036 ‡, against the actual row. A store holding the token would let
        // anyone who read the table act as any user.
        $this->applyLifetime();
        $token = $this->establishSessionFor($this->createUser(), '2026-08-20T12:00:00Z');

        /** @var list<object{token_hash: string}> $rows */
        $rows = $this->applicationConnection()->select(
            'SELECT token_hash FROM '.DatabaseSessionRepository::TABLE
        );

        self::assertCount(1, $rows);
        self::assertNotSame($token, $rows[0]->token_hash);
        self::assertSame($this->tokens()->hash($token), $rows[0]->token_hash);
    }

    public function test_two_tokens_never_collide_in_practice(): void
    {
        // SEC-035 ‡: sufficient entropy that guessing is infeasible. A weak
        // generator is the failure mode that looks like it is working, so the
        // property is exercised rather than assumed.
        $tokens = [];

        for ($i = 0; $i < 200; $i++) {
            $tokens[] = $this->tokens()->generate();
        }

        self::assertCount(200, array_unique($tokens));
        self::assertSame([64], array_values(array_unique(array_map(strlen(...), $tokens))));
    }

    private function resolver(): ResolveSession
    {
        return $this->app->make(ResolveSession::class);
    }

    private function sessions(): SessionRepository
    {
        return $this->app->make(SessionRepository::class);
    }

    private function tokens(): HashesSessionTokens
    {
        return $this->app->make(HashesSessionTokens::class);
    }

    /**
     * `BADR-12`: a policy value is applied by an operator action, so the test
     * applies it the way an operator would rather than writing the row.
     */
    private function applyLifetime(): void
    {
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY,
            self::LIFETIME_SECONDS,
            'operator-under-test',
        );
    }

    private function createUser(): UserReference
    {
        $this->applicationConnection()->insert(
            'INSERT INTO op_users (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [self::REFERENCE, self::PHONE, 'VERIFIED', 'ACTIVE', '2026-08-20 12:00:00.000000', '2026-08-20 12:00:00.000000'],
        );

        return UserReference::fromString(self::REFERENCE);
    }

    private function establishSessionFor(UserReference $user, string $at, string $seed = 'first'): string
    {
        $token = $this->tokens()->generate().$seed;

        $this->sessions()->save(Session::establish(
            $user,
            $this->tokens()->hash($token),
            Instant::fromString($at),
        ));

        return $token;
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

        // BE-170: the store and the cache are the same object, so a value
        // deleted underneath it must be forgotten or the next read serves it.
        $this->app->make(DatabasePolicyStore::class)->forget(PolicyServiceProvider::sessionLifetime());
    }
}
