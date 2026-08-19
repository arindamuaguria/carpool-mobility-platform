<?php

declare(strict_types=1);

namespace Tests\Integration\Policy;

use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\Shared\Policy\RecordsPolicyChanges;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyNotDeclared;
use Cmp\Domain\Shared\Policy\PolicyNotSet;
use Cmp\Domain\Shared\Policy\PolicyRegister;
use Cmp\Domain\Shared\Policy\PolicyType;
use Cmp\Domain\Shared\Policy\PolicyValueInvalid;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Infrastructure\Persistence\Policy\DatabasePolicyStore;
use PDO;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\Integration\IntegrationTestCase;
use Throwable;

/**
 * CMP-IMP-031 — the versioned store, against a real MySQL.
 *
 * Level 3 (`TC-030` ‡). Versioning (`DB-152`), the actor and previous-value
 * record (`BE-173`) and cache invalidation across a change (`BE-170`) are all
 * properties of what is written and read back, and cannot be proven against a
 * double.
 *
 * The key used here is a **test key** in a register of this test's own. The
 * platform's register is empty on purpose (`DB-153` ‡), and exercising the store
 * must never require adding a key to it.
 */
final class PolicyStoreTest extends IntegrationTestCase
{
    use ClearsTheEvidentialLog;

    private const KEY = 'test.retry_limit';

    private PolicyKey $key;

    private PolicyRegister $register;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = PolicyKey::of(self::KEY, PolicyType::Integer, 'how many times a test double retries');
        $this->register = PolicyRegister::of($this->key);

        $this->clearStore();
        $this->clearEvidentialLog();
    }

    protected function tearDown(): void
    {
        $this->clearStore();
        $this->clearEvidentialLog();

        parent::tearDown();
    }

    public function test_a_declared_key_with_no_value_is_rejected_rather_than_defaulted(): void
    {
        // SRS-REQ-158: an attempt to use an unconfigured value is rejected.
        // SRS-REQ-113 forbids synthesising one, and a default here would be a
        // business decision taken by the platform on nobody's authority.
        self::assertFalse($this->store()->isSet($this->key));

        $this->expectException(PolicyNotSet::class);
        $this->store()->read($this->key);
    }

    public function test_an_undeclared_key_raises_and_never_reaches_the_store(): void
    {
        // DB-153 ‡ / BE-172 ‡.
        $undeclared = PolicyKey::of('test.not_declared', PolicyType::Text, 'p');

        $this->expectException(PolicyNotDeclared::class);
        $this->store()->read($undeclared);
    }

    public function test_a_change_appends_a_version_rather_than_updating_in_place(): void
    {
        // DB-152 / BE-167.
        $this->change()->apply(self::KEY, '3', 'operator-1');
        $this->change()->apply(self::KEY, '5', 'operator-2');

        $versions = $this->applicationConnection()->select(
            'SELECT v.version, v.value_text, v.previous_value_text, v.applied_by'
            .' FROM '.DatabasePolicyStore::VERSIONS_TABLE.' AS v'
            .' INNER JOIN '.DatabasePolicyStore::VALUES_TABLE.' AS pv ON pv.id = v.policy_value_id'
            .' WHERE pv.policy_key = ? ORDER BY v.version',
            [self::KEY],
        );

        self::assertCount(2, $versions, 'The first version must still be there.');

        // BE-173: actor, previous value and new value.
        self::assertSame('3', $versions[0]->value_text);
        self::assertNull($versions[0]->previous_value_text);
        self::assertSame('operator-1', $versions[0]->applied_by);

        self::assertSame('5', $versions[1]->value_text);
        self::assertSame('3', $versions[1]->previous_value_text);
        self::assertSame('operator-2', $versions[1]->applied_by);
    }

    public function test_a_value_read_back_carries_the_version_it_came_from(): void
    {
        // BE-167: a decision records the version it used.
        $this->change()->apply(self::KEY, '3', 'operator-1');
        $this->change()->apply(self::KEY, '5', 'operator-1');

        $value = $this->store()->read($this->key);

        self::assertSame(5, $value->asInteger());
        self::assertSame(2, $value->version());
    }

    public function test_a_change_invalidates_the_cache_without_a_restart(): void
    {
        // BE-169 / BE-170. The read below is memoised; the change must clear it.
        $this->change()->apply(self::KEY, '3', 'operator-1');
        $store = $this->store();

        self::assertSame(3, $store->read($this->key)->asInteger());

        $this->change($store)->apply(self::KEY, '9', 'operator-1');

        self::assertSame(9, $store->read($this->key)->asInteger(), 'BE-170: no restart required.');
    }

    public function test_an_invalid_value_is_rejected_rather_than_applied(): void
    {
        // BE-174 / ARCH-148. BADR-12: "a mistyped cancellation window becomes a
        // runtime failure in a payment path".
        $this->change()->apply(self::KEY, '3', 'operator-1');

        try {
            $this->change()->apply(self::KEY, 'three', 'operator-1');
            self::fail('An invalid value must be rejected.');
        } catch (PolicyValueInvalid) {
            // Expected.
        }

        // Rejected *rather than applied*: the store is untouched.
        self::assertSame(3, $this->store()->read($this->key)->asInteger());
        self::assertSame(1, $this->versionCount());
    }

    public function test_an_undeclared_key_cannot_be_changed(): void
    {
        // DB-153 ‡: the register is not advisory. Nothing is written.
        try {
            $this->change()->apply('test.not_declared', 'anything', 'operator-1');
            self::fail('An undeclared key must be refused.');
        } catch (PolicyNotDeclared) {
            // Expected.
        }

        $rows = $this->applicationConnection()->select(
            'SELECT COUNT(*) AS total FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
            ['test.not_declared'],
        );

        self::assertSame(0, (int) $rows[0]->total);
    }

    public function test_no_monetary_precision_is_lost_in_the_round_trip(): void
    {
        // DB-033 ‡. A decimal policy value is stored and read back as text; there
        // is no point at which it passes through a float.
        $key = PolicyKey::of('test.fee', PolicyType::Decimal, 'a fee used by a test double');
        $register = PolicyRegister::of($key);
        $store = new DatabasePolicyStore($this->applicationConnection(), $register);

        $this->changeWith($register, $store)->apply('test.fee', '12345678901234567890.05', 'operator-1');

        self::assertSame('12345678901234567890.05', $store->read($key)->asDecimal());

        $this->migrationConnection()->delete(
            'DELETE FROM '.DatabasePolicyStore::VERSIONS_TABLE.' WHERE policy_value_id IN'
            .' (SELECT id FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?)',
            ['test.fee'],
        );
        $this->migrationConnection()->delete(
            'DELETE FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
            ['test.fee'],
        );
    }

    public function test_the_database_refuses_two_versions_with_the_same_number(): void
    {
        // The constraint is what decides, so it is asserted directly. DB-142 ‡
        // makes the same choice for the idempotency registry: "rejected by the
        // database rather than by a race-prone read."
        $this->change()->apply(self::KEY, '3', 'operator-1');

        $declarationId = $this->declarationId();

        self::assertTrue(
            $this->refused(fn () => $this->migrationConnection()->insert(
                'INSERT INTO '.DatabasePolicyStore::VERSIONS_TABLE
                .' (policy_value_id, version, value_text, previous_value_text, applied_by, applied_at)'
                .' VALUES (?, ?, ?, ?, ?, ?)',
                [$declarationId, 1, '9', '3', 'operator-2', '2026-08-19 09:30:00'],
            )),
            'DB-152: one version number per value, decided by the database.',
        );
    }

    public function test_a_change_racing_a_committed_change_is_refused_rather_than_overwriting(): void
    {
        // A genuine race under MySQL's default REPEATABLE READ: this transaction
        // takes its snapshot, another operator commits version 2 underneath it,
        // and this one still computes 2 from its own snapshot. The unique
        // constraint refuses the insert, and the loser is told (BE-173 records a
        // change *from* a specific value, so a silent retry would record a
        // previous value that was never current).
        $this->change()->apply(self::KEY, '3', 'operator-1');

        $declarationId = $this->declarationId();
        $recorder = $this->app->make(RecordsPolicyChanges::class);
        $connection = $this->applicationConnection();

        $connection->beginTransaction();

        try {
            // Establishes this transaction's snapshot at version 1.
            $connection->select(
                'SELECT MAX(version) AS highest FROM '.DatabasePolicyStore::VERSIONS_TABLE
                .' WHERE policy_value_id = ?',
                [$declarationId],
            );

            // Another operator commits version 2 on a separate session.
            $other = $this->rawConnection();
            $statement = $other->prepare(
                'INSERT INTO '.DatabasePolicyStore::VERSIONS_TABLE
                .' (policy_value_id, version, value_text, previous_value_text, applied_by, applied_at)'
                .' VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$declarationId, 2, '5', '3', 'operator-2', '2026-08-19 09:30:00']);

            self::assertTrue(
                $this->refused(static fn () => $recorder->appendVersion(
                    PolicyKey::of(self::KEY, PolicyType::Integer, 'p'),
                    '7',
                    '3',
                    'operator-3',
                )),
                'The losing operator must be refused, not allowed to overwrite.',
            );
        } finally {
            $connection->rollBack();
        }

        // The winner's value stands.
        self::assertSame(5, $this->store()->read($this->key)->asInteger());
        self::assertSame(2, $this->versionCount());
    }

    private function declarationId(): int
    {
        $rows = $this->applicationConnection()->select(
            'SELECT id FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
            [self::KEY],
        );

        return (int) $rows[0]->id;
    }

    private function rawConnection(): PDO
    {
        return new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s',
                $this->connectionSetting('mysql', 'host'),
                $this->connectionSetting('mysql', 'port'),
                $this->connectionSetting('mysql', 'database'),
            ),
            $this->connectionSetting('mysql', 'username'),
            $this->connectionSetting('mysql', 'password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    private function refused(callable $operation): bool
    {
        try {
            $operation();
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    private function versionCount(): int
    {
        $rows = $this->applicationConnection()->select(
            'SELECT COUNT(*) AS total FROM '.DatabasePolicyStore::VERSIONS_TABLE.' AS v'
            .' INNER JOIN '.DatabasePolicyStore::VALUES_TABLE.' AS pv ON pv.id = v.policy_value_id'
            .' WHERE pv.policy_key = ?',
            [self::KEY],
        );

        return (int) $rows[0]->total;
    }

    public function test_a_change_is_evidenced(): void
    {
        // ARCH-115 / DB-154 / BE-173, and BADR-12: "every change is written by an
        // operator action, validated before application, and recorded
        // evidentially."
        $this->change()->apply(self::KEY, '3', 'operator-1');

        $rows = $this->evidentialRecords();

        self::assertCount(1, $rows);
        self::assertSame(ChangePolicyValue::ACTION, $rows[0]->action);
        self::assertSame('operator-1', $rows[0]->actor);
        self::assertSame(EvidentialOutcome::Succeeded->value, $rows[0]->outcome);
    }

    public function test_the_record_points_at_the_one_version_holding_the_values(): void
    {
        // BE-173 asks for the previous and new value, and BE-107 ‡ fixes the
        // evidential record at six fields with no place for either — BE-201 ‡
        // being why there is no seventh. DB-152's versioned record holds them, so
        // the subject resolves to exactly one of those rows and the pair carries
        // between them what BE-173 asks for.
        $this->change()->apply(self::KEY, '3', 'operator-1');
        $this->change()->apply(self::KEY, '5', 'operator-2');

        $rows = $this->evidentialRecords();
        self::assertCount(2, $rows);
        self::assertSame(self::KEY.'@v1', $rows[0]->subject);
        self::assertSame(self::KEY.'@v2', $rows[1]->subject);

        $version = $this->applicationConnection()->select(
            'SELECT v.version, v.value_text, v.previous_value_text, v.applied_by FROM '
            .DatabasePolicyStore::VERSIONS_TABLE.' AS v'
            .' INNER JOIN '.DatabasePolicyStore::VALUES_TABLE.' AS pv ON pv.id = v.policy_value_id'
            .' WHERE pv.policy_key = ? AND v.version = ?',
            [self::KEY, 2],
        );

        self::assertCount(1, $version);
        self::assertSame('3', $version[0]->previous_value_text);
        self::assertSame('5', $version[0]->value_text);
        self::assertSame('operator-2', $version[0]->applied_by);
    }

    public function test_a_rejected_change_is_not_evidenced_because_nothing_happened(): void
    {
        // BE-174 / ARCH-148 reject an invalid value before the transaction opens,
        // so there is no change to evidence. A record here would assert that the
        // platform did something it did not do.
        try {
            $this->change()->apply(self::KEY, 'not-an-integer', 'operator-1');
            self::fail('BE-174: an invalid value must be rejected rather than applied.');
        } catch (PolicyValueInvalid) {
            // expected
        }

        self::assertSame([], $this->evidentialRecords());
    }

    public function test_the_change_and_its_record_commit_together(): void
    {
        // BE-106 ‡: the evidential record is written in the same transaction as
        // the operation it evidences. Counting both after a change is the
        // observable half of that; the other half is that neither can be present
        // without the other, which follows from there being one transaction.
        $this->change()->apply(self::KEY, '3', 'operator-1');

        self::assertSame(1, $this->versionCount());
        self::assertCount(1, $this->evidentialRecords());
    }

    /**
     * @return list<object{actor: string, action: string, subject: string, outcome: string}>
     */
    private function evidentialRecords(): array
    {
        /** @var list<object{actor: string, action: string, subject: string, outcome: string}> $rows */
        $rows = $this->applicationConnection()->select(
            'SELECT actor, action, subject, outcome FROM ev_evidential_records ORDER BY id ASC'
        );

        return $rows;
    }

    private function store(): DatabasePolicyStore
    {
        return new DatabasePolicyStore($this->applicationConnection(), $this->register);
    }

    private function change(?DatabasePolicyStore $store = null): ChangePolicyValue
    {
        return $this->changeWith($this->register, $store ?? $this->store());
    }

    private function changeWith(PolicyRegister $register, DatabasePolicyStore $store): ChangePolicyValue
    {
        return new ChangePolicyValue(
            $this->app->make(TransactionBoundary::class),
            $register,
            $this->app->make(RecordsPolicyChanges::class),
            $store,
            // ARCH-115 / DB-154 / BE-173: a change is evidenced. The real writer,
            // because BE-106 ‡ puts the record in the same transaction and a
            // double that skipped it would prove nothing about that.
            $this->app->make(RecordsEvidence::class),
            $this->app->make(Clock::class),
        );
    }

    private function clearStore(): void
    {
        foreach ([self::KEY, 'test.not_declared', 'test.fee'] as $key) {
            $this->migrationConnection()->delete(
                'DELETE FROM '.DatabasePolicyStore::VERSIONS_TABLE.' WHERE policy_value_id IN'
                .' (SELECT id FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?)',
                [$key],
            );
            $this->migrationConnection()->delete(
                'DELETE FROM '.DatabasePolicyStore::VALUES_TABLE.' WHERE policy_key = ?',
                [$key],
            );
        }
    }
}
