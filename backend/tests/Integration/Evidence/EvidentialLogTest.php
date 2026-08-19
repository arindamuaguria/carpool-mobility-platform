<?php

declare(strict_types=1);

namespace Tests\Integration\Evidence;

use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidenceNotRecorded;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Evidence\VerifiesEvidentialChain;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Shared\Time\Instant;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Evidential\KeyedChainHash;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Tests\Integration\IntegrationTestCase;
use Throwable;

/**
 * FEAT-033 — the evidential log, against a real MySQL.
 *
 * Level 3 (`TC-030` ‡). Almost nothing here is provable anywhere else: the
 * withheld privilege is a grant, the trigger is a schema object, and the chain
 * is a property of what is actually stored.
 *
 * `TC-048` ‡ and `TC-050` ‡ require an automated check that **attempts** the
 * forbidden operation and requires the server to refuse. `BE-212` ‡ requires
 * verification to be tested against a **deliberately altered record**. Both are
 * here.
 */
final class EvidentialLogTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearLog();
    }

    protected function tearDown(): void
    {
        $this->clearLog();

        parent::tearDown();
    }

    public function test_the_first_record_chains_to_the_genesis_value(): void
    {
        // DB-110 ‡ requires the previous hash NOT NULL, so the chain has a defined
        // beginning rather than a nullable special case every reader must
        // remember.
        $this->writer()->record($this->anEvidence('ride.published', 'ride-1'));

        $rows = $this->applicationConnection()->select(
            'SELECT previous_hash, chain_algorithm FROM '.DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
        );

        self::assertCount(1, $rows);
        self::assertSame(KeyedChainHash::GENESIS, $rows[0]->previous_hash);
        self::assertSame(KeyedChainHash::ALGORITHM, $rows[0]->chain_algorithm);
    }

    public function test_each_record_chains_to_its_predecessor(): void
    {
        // SEC-105 ‡, DB-111 ‡: ordered by the monotonic, never-reused key.
        $this->writer()->record($this->anEvidence('ride.published', 'ride-1'));
        $this->writer()->record($this->anEvidence('booking.confirmed', 'booking-1'));
        $this->writer()->record($this->anEvidence('trip.started', 'trip-1'));

        $rows = $this->applicationConnection()->select(
            'SELECT previous_hash, record_hash FROM '.DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
        );

        self::assertCount(3, $rows);
        self::assertSame($rows[0]->record_hash, $rows[1]->previous_hash);
        self::assertSame($rows[1]->record_hash, $rows[2]->previous_hash);
    }

    public function test_a_refusal_is_evidenced_with_its_reason(): void
    {
        // BE-114 ‡.
        $this->writer()->record(Evidence::of(
            ActorReference::fromString('actor-1'),
            'booking.requested',
            'booking-1',
            EvidentialOutcome::Refused,
            $this->anInstant(),
            'booking.seats_no_longer_available',
        ));

        $rows = $this->applicationConnection()->select(
            'SELECT outcome, reason FROM '.DatabaseEvidentialWriter::TABLE
        );

        self::assertSame('refused', $rows[0]->outcome);
        self::assertSame('booking.seats_no_longer_available', $rows[0]->reason);
    }

    public function test_the_application_account_cannot_update_a_record(): void
    {
        // TC-048 ‡ / DB-118 ‡ / BE-110 ‡ / constraint 8 of the §15 register.
        // SEC-110 ‡: this withheld privilege is the **primary** defence.
        $this->writer()->record($this->anEvidence('ride.published', 'ride-1'));

        self::assertTrue(
            $this->refused(fn () => $this->applicationConnection()->update(
                'UPDATE '.DatabaseEvidentialWriter::TABLE.' SET subject = ? WHERE subject = ?',
                ['tampered', 'ride-1'],
            )),
            'DB-118 ‡: the application account holds no UPDATE on the evidential domain.',
        );
    }

    public function test_the_application_account_cannot_delete_a_record(): void
    {
        $this->writer()->record($this->anEvidence('ride.published', 'ride-1'));

        self::assertTrue(
            $this->refused(fn () => $this->applicationConnection()->delete(
                'DELETE FROM '.DatabaseEvidentialWriter::TABLE
            )),
        );
    }

    public function test_the_trigger_refuses_an_update_even_from_an_account_that_holds_the_privilege(): void
    {
        // DB-120 ‡: the second layer, "as a second layer and not as the primary
        // mechanism". The migration account holds UPDATE on the schema, and the
        // trigger refuses it anyway.
        $this->writer()->record($this->anEvidence('ride.published', 'ride-1'));

        self::assertTrue(
            $this->refused(fn () => $this->migrationConnection()->update(
                'UPDATE '.DatabaseEvidentialWriter::TABLE.' SET subject = ? WHERE subject = ?',
                ['tampered', 'ride-1'],
            )),
            'DB-120 ‡: a trigger rejects UPDATE regardless of privilege.',
        );
    }

    public function test_the_trigger_refuses_a_delete_even_from_an_account_that_holds_the_privilege(): void
    {
        $this->writer()->record($this->anEvidence('ride.published', 'ride-1'));

        self::assertTrue(
            $this->refused(fn () => $this->migrationConnection()->delete(
                'DELETE FROM '.DatabaseEvidentialWriter::TABLE
            )),
        );
    }

    public function test_verification_reports_an_intact_chain(): void
    {
        // BE-115 / DB-115 / SEC-111.
        foreach (['ride-1', 'booking-1', 'trip-1'] as $subject) {
            $this->writer()->record($this->anEvidence('thing.happened', $subject));
        }

        $verification = $this->verifier()->verify();

        self::assertTrue($verification->isIntact());
        self::assertSame(3, $verification->recordsVerified());
        self::assertNull($verification->divergedAtRecord());
    }

    public function test_verification_detects_a_deliberately_altered_record(): void
    {
        // BE-212 ‡: "Evidential chain verification shall be tested against a
        // deliberately altered record."
        //
        // Both defences must be defeated to stage this — the withheld privilege
        // and the trigger — which is exactly the attacker SADR-07's key is for:
        // "the key defends against an attacker who defeats it" (SEC-110 ‡).
        foreach (['ride-1', 'booking-1', 'trip-1'] as $subject) {
            $this->writer()->record($this->anEvidence('thing.happened', $subject));
        }

        $this->tamper('UPDATE '.DatabaseEvidentialWriter::TABLE.' SET subject = ? WHERE subject = ?', ['tampered', 'booking-1']);

        $verification = $this->verifier()->verify();

        self::assertFalse($verification->isIntact());
        self::assertSame(2, $verification->divergedAtRecord(), 'SEC-111: the first divergence, with its record.');
        self::assertStringContainsString('altered', (string) $verification->divergence());
    }

    public function test_verification_detects_a_removed_record(): void
    {
        // An unkeyed chain would not survive this either, but a removed record is
        // the case a naive "each hash matches its own content" check misses.
        foreach (['ride-1', 'booking-1', 'trip-1'] as $subject) {
            $this->writer()->record($this->anEvidence('thing.happened', $subject));
        }

        $this->tamper('DELETE FROM '.DatabaseEvidentialWriter::TABLE.' WHERE subject = ?', ['booking-1']);

        $verification = $this->verifier()->verify();

        self::assertFalse($verification->isIntact());
        self::assertStringContainsString('removed, inserted or reordered', (string) $verification->divergence());
    }

    public function test_verification_never_repairs_what_it_finds(): void
    {
        // SEC-112 ‡ / DB-115: "a break is a finding, not a fault to correct."
        foreach (['ride-1', 'booking-1'] as $subject) {
            $this->writer()->record($this->anEvidence('thing.happened', $subject));
        }

        $this->tamper('UPDATE '.DatabaseEvidentialWriter::TABLE.' SET subject = ? WHERE subject = ?', ['tampered', 'booking-1']);

        $this->verifier()->verify();
        $this->verifier()->verify();

        $rows = $this->readConnection()->select(
            'SELECT subject FROM '.DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
        );

        self::assertSame('tampered', $rows[1]->subject, 'The break is still there. Nothing repaired it.');
    }

    public function test_an_operation_is_not_reported_complete_when_its_record_cannot_be_written(): void
    {
        // FRD-FR-248 ‡ / BE-049 ‡ / BE-106 ‡: the record and the operation commit
        // together, so a failed write rolls the operation back and there is
        // nothing to report.
        $writer = $this->writer();
        $connection = $this->applicationConnection();

        $propagated = false;

        try {
            $this->app->make(TransactionBoundary::class)->transactional(function () use ($writer, $connection): void {
                // Stands in for the operation's own effect.
                $connection->insert(
                    'INSERT INTO '.DatabaseEvidentialWriter::TABLE
                    .' (actor, action, subject, outcome, occurred_at, previous_hash, record_hash, chain_algorithm)'
                    .' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    ['actor-1', 'the.effect', 'effect-1', 'succeeded', '2026-08-19 09:30:00', 'x', 'y', 'z'],
                );

                // An action longer than the column, so the record cannot be
                // written.
                $writer->record($this->anEvidence(str_repeat('x', 200), 'ride-1'));
            });
        } catch (EvidenceNotRecorded) {
            $propagated = true;
        } catch (Throwable) {
            $propagated = true;
        }

        self::assertTrue($propagated, 'FRD-FR-248 ‡: the failure must not be swallowed.');

        $rows = $this->readConnection()->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseEvidentialWriter::TABLE
        );

        self::assertSame(0, (int) $rows[0]->total, 'The operation rolled back with the record it could not write.');
    }

    private function writer(): RecordsEvidence
    {
        return $this->app->make(RecordsEvidence::class);
    }

    private function verifier(): VerifiesEvidentialChain
    {
        return $this->app->make(VerifiesEvidentialChain::class);
    }

    private function anEvidence(string $action, string $subject): Evidence
    {
        return Evidence::of(
            ActorReference::fromString('actor-1'),
            $action,
            $subject,
            EvidentialOutcome::Succeeded,
            $this->anInstant(),
        );
    }

    private function anInstant(): Instant
    {
        return Instant::fromString('2026-08-19T09:30:00Z');
    }

    /**
     * Stages an alteration by defeating both defences, which is the only way one
     * can be staged — and the point of `SEC-110` ‡.
     *
     * @param  list<string>  $bindings
     */
    private function tamper(string $statement, array $bindings): void
    {
        $privileged = $this->provisioningConnection();

        $privileged->statement('DROP TRIGGER IF EXISTS ev_evidential_records_refuse_update');
        $privileged->statement('DROP TRIGGER IF EXISTS ev_evidential_records_refuse_delete');
        $privileged->statement($statement, $bindings);
    }

    private function restoreTriggers(): void
    {
        $privileged = $this->provisioningConnection();

        $privileged->statement('DROP TRIGGER IF EXISTS ev_evidential_records_refuse_update');
        $privileged->statement('DROP TRIGGER IF EXISTS ev_evidential_records_refuse_delete');
        $privileged->unprepared(
            'CREATE TRIGGER ev_evidential_records_refuse_update BEFORE UPDATE ON ev_evidential_records FOR EACH ROW '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DB-108 : an evidential record is never updated by any code path.'"
        );
        $privileged->unprepared(
            'CREATE TRIGGER ev_evidential_records_refuse_delete BEFORE DELETE ON ev_evidential_records FOR EACH ROW '
            ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'DB-108 : an evidential record is never deleted by any code path.'"
        );
    }

    private function clearLog(): void
    {
        $privileged = $this->provisioningConnection();

        $privileged->statement('DROP TRIGGER IF EXISTS ev_evidential_records_refuse_delete');
        $privileged->delete('DELETE FROM '.DatabaseEvidentialWriter::TABLE);
        $privileged->statement('ALTER TABLE '.DatabaseEvidentialWriter::TABLE.' AUTO_INCREMENT = 1');

        // Both defences go back before any test body runs. A test that found
        // them missing would be asserting nothing.
        $this->restoreTriggers();
    }

    private function provisioningConnection(): Connection
    {
        $connection = $this->app->make(ConnectionResolverInterface::class)->connection('mysql_provisioning');

        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
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
}
