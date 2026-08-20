<?php

declare(strict_types=1);

namespace Tests\Integration\Persistence;

use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Integration\IntegrationTestCase;
use Throwable;

/**
 * `CMP-IMP-051` — the first `op_` tables, checked against a real server.
 *
 * Level 3 (`TC-030` ‡). `DB-207` ‡ requires each constraint in the register to
 * have **a test that attempts to violate it and requires the database to
 * refuse**, and constraints 4, 22 and 23 are this file's. `DB-208` requires the
 * test to arrive in the change that adds the constraint, which is this one.
 *
 * The tables were undecidable until 2026-08-20: `BAD-RULE-043` fixed the
 * identifying detail, `BAD-RULE-006` and `BAD-RULE-010` the two vocabularies, and
 * `SEC-025` settled that there is **no account lockout** and therefore no column
 * holding one.
 */
final class UserSchemaTest extends IntegrationTestCase
{
    private const PHONE = '+910000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearUsers();
    }

    protected function tearDown(): void
    {
        $this->clearUsers();

        parent::tearDown();
    }

    public function test_a_second_account_cannot_take_a_registered_phone_number(): void
    {
        // Negative test for constraint 22 — FRD-FR-004 / DB-028 / DB-209 ‡.
        // DB-209 ‡ is the reason this is a constraint and not only a check in the
        // application: "where a rule is enforceable by the database and by the
        // application, it shall be enforced by both."
        $this->insertUser('external-1', self::PHONE);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertUser('external-2', self::PHONE);
    }

    public function test_two_accounts_cannot_share_an_external_identifier(): void
    {
        // Negative test for constraint 4 — API-014 ‡ / DB-022 ‡. A repeated
        // external identifier would make two accounts indistinguishable through
        // the interface, which is the one thing the identifier exists to prevent.
        $this->insertUser('external-1', self::PHONE);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertUser('external-1', '+910000000002');
    }

    public function test_two_sessions_cannot_share_a_token_hash(): void
    {
        // Negative test for constraint 23 — SEC-036 ‡ / SEC-042. Validation is a
        // hash-and-lookup, which is only a lookup if the hash resolves to at most
        // one session.
        $userId = $this->insertUser('external-1', self::PHONE);
        $this->insertSession($userId, 'a-token-hash-of-thirty-two-bytes');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertSession($userId, 'a-token-hash-of-thirty-two-bytes');
    }

    public function test_a_user_cannot_be_deleted_while_a_credential_references_it(): void
    {
        // Negative test for constraint 15 — DB-030 ‡ / DADR-12. The delete is
        // RESTRICT rather than CASCADE precisely so that removing a person does
        // not silently remove what references them; DADR-12 removes personal data
        // in place instead.
        $userId = $this->insertUser('external-1', self::PHONE);
        $this->insertCredential($userId);

        self::assertTrue($this->refused(fn () => $this->migrationConnection()->delete(
            'DELETE FROM op_users WHERE id = ?', [$userId],
        )));
    }

    public function test_a_user_cannot_be_deleted_while_a_session_references_it(): void
    {
        // The same for constraint 15's second key. Both are asserted, because a
        // RESTRICT that held on one table and cascaded on the other would pass a
        // test that checked only one.
        $userId = $this->insertUser('external-1', self::PHONE);
        $this->insertSession($userId, 'a-token-hash-of-thirty-two-bytes');

        self::assertTrue($this->refused(fn () => $this->migrationConnection()->delete(
            'DELETE FROM op_users WHERE id = ?', [$userId],
        )));
    }

    public function test_there_is_no_column_holding_an_account_lockout(): void
    {
        // SEC-025, decided 2026-08-20: no account lockout. A lockout would be a
        // condition of the account needing a column to hold it and a rule to
        // clear it; a rate bound is a question asked of the attempts DB-043
        // retains. This asserts the decision left no residue in the schema.
        $columns = $this->columnsOf('op_users');

        foreach ($columns as $column) {
            self::assertStringNotContainsString('lock', $column, 'SEC-025: there is no account lockout.');
            self::assertStringNotContainsString('attempt', $column, 'SEC-025: the bound is against retained attempts, not a counter here.');
        }
    }

    public function test_the_account_carries_only_the_one_mandatory_identifying_detail(): void
    {
        // BAD-RULE-043, and SEC-089 / SEC-090 behind it: personal data is
        // minimised to what a stated purpose requires, and an element with no
        // stated purpose is a review finding rather than a default acceptance.
        // Asserted as an absence, because that is the shape of the decision.
        $columns = $this->columnsOf('op_users');

        self::assertSame(
            ['id', 'external_id', 'phone_number', 'verification_standing', 'account_state', 'created_at', 'updated_at'],
            $columns,
        );

        foreach (['name', 'email', 'date_of_birth', 'dob', 'address', 'gender', 'photo'] as $notCollected) {
            self::assertNotContains($notCollected, $columns);
        }
    }

    public function test_neither_vocabulary_is_a_database_enum(): void
    {
        // DB-039 / DB-045: account state references configuration rather than a
        // database ENUM, so the value set can change without a schema migration.
        // A CHECK listing the values would be an ENUM by another name, and
        // DB-214 ‡ forbids a migration encoding a business rule — both sets are
        // absolute rules and live in the Domain, where BE-012 puts them.
        foreach (['verification_standing', 'account_state'] as $column) {
            self::assertSame('varchar', $this->columnTypeOf('op_users', $column));
        }

        self::assertSame([], $this->checkConstraintsOn('op_users'));
    }

    public function test_authentication_material_is_absent_from_the_account(): void
    {
        // DB-042 ‡ / SRS-REQ-098 ‡: authentication material is held in
        // op_user_credentials in non-recoverable form and shall NEVER appear on
        // op_users. Asserted on the schema, because a column that existed would
        // be a place for it to appear whatever the code did.
        foreach ($this->columnsOf('op_users') as $column) {
            foreach (['password', 'credential', 'secret', 'token', 'hash', 'material'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $column, 'DB-042 ‡: never on op_users.');
            }
        }
    }

    public function test_a_terminated_session_is_recorded_rather_than_removed(): void
    {
        // DB-044 ‡ / SEC-040 ‡: recorded as terminated and not removed, so that
        // reuse is detectable rather than merely impossible. The column exists and
        // is nullable — a session that has not ended has no termination instant.
        self::assertContains('terminated_at', $this->columnsOf('op_sessions'));
        self::assertTrue($this->isNullable('op_sessions', 'terminated_at'));
        self::assertFalse($this->isNullable('op_sessions', 'established_at'));
    }

    public function test_a_session_carries_no_frozen_expiry(): void
    {
        // SEC-039 makes the session lifetime policy configuration and BE-170
        // invalidates the cache on change so that no restart is required. An
        // expiry frozen at establishment would make a shortened bound not apply
        // to the sessions it was shortened for.
        self::assertNotContains('expires_at', $this->columnsOf('op_sessions'));
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        /** @var list<object{COLUMN_NAME: string}> $rows */
        $rows = $this->readConnection()->select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        );

        return array_map(static fn (object $row): string => $row->COLUMN_NAME, $rows);
    }

    private function columnTypeOf(string $table, string $column): string
    {
        /** @var list<object{DATA_TYPE: string}> $rows */
        $rows = $this->readConnection()->select(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        self::assertCount(1, $rows);

        return $rows[0]->DATA_TYPE;
    }

    private function isNullable(string $table, string $column): bool
    {
        /** @var list<object{IS_NULLABLE: string}> $rows */
        $rows = $this->readConnection()->select(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        self::assertCount(1, $rows);

        return $rows[0]->IS_NULLABLE === 'YES';
    }

    /**
     * @return list<string>
     */
    private function checkConstraintsOn(string $table): array
    {
        /** @var list<object{CONSTRAINT_NAME: string}> $rows */
        $rows = $this->readConnection()->select(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS'
            .' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, 'CHECK'],
        );

        return array_map(static fn (object $row): string => $row->CONSTRAINT_NAME, $rows);
    }

    private function insertUser(string $externalId, string $phoneNumber): int
    {
        $connection = $this->applicationConnection();

        $connection->insert(
            'INSERT INTO op_users (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [str_pad($externalId, 32, '0'), $phoneNumber, 'UNVERIFIED', 'ACTIVE', self::NOW, self::NOW],
        );

        /** @var list<object{id: int}> $rows */
        $rows = $connection->select('SELECT id FROM op_users WHERE phone_number = ?', [$phoneNumber]);

        return (int) $rows[0]->id;
    }

    private function insertCredential(int $userId): void
    {
        $this->applicationConnection()->insert(
            'INSERT INTO op_user_credentials (user_id, material, issued_at) VALUES (?, ?, ?)',
            [$userId, 'not-a-real-hash', self::NOW],
        );
    }

    private function insertSession(int $userId, string $tokenHash): void
    {
        $this->applicationConnection()->insert(
            'INSERT INTO op_sessions (user_id, token_hash, established_at) VALUES (?, ?, ?)',
            [$userId, $tokenHash, self::NOW],
        );
    }

    /**
     * The application account holds no `DELETE` on `op_`? It does — `DADR-09`
     * grants it there — so the clear-down runs on the migration account only for
     * the foreign keys, which must be removed child-first.
     */
    private function clearUsers(): void
    {
        $migration = $this->migrationConnection();

        $migration->delete('DELETE FROM op_sessions');
        $migration->delete('DELETE FROM op_user_credentials');
        $migration->delete('DELETE FROM op_users');
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

    private const NOW = '2026-08-20 12:00:00.000000';
}
