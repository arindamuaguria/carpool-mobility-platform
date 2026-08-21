<?php

declare(strict_types=1);

namespace Tests\Integration\User;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Failure\Failure;
use Cmp\Application\Shared\Failure\InvalidRequest;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\User\AmendEmergencyContact;
use Cmp\Application\User\AuthenticatedCaller;
use Cmp\Application\User\ContactSetView;
use Cmp\Application\User\ContactView;
use Cmp\Application\User\EmergencyContactCommand;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\NominateEmergencyContact;
use Cmp\Application\User\ReadEmergencyContacts;
use Cmp\Application\User\RemoveEmergencyContact;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\EmergencyContactRefusal;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Persistence\User\DatabaseEmergencyContactRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * `UC-048` against a real store — `FRD-FR-181` to `FRD-FR-184`.
 *
 * **Level 3** (`TC-025`, `TC-030`): a real MySQL that enforces its constraints,
 * never an in-memory substitute. Three things can only be answered here.
 *
 * - The **set rule** has two enforcers. `NominatedContacts` refuses with a reason
 *   a caller can read, and `op_user_emergency_contacts_user_number_unique`
 *   decides a race (`DB-142` ‡). Level 2 proved the first; only this level can
 *   see the second.
 * - **`FRD-FR-184`** — recorded against the user — is a claim about rows.
 * - **`BE-201` ‡**: the evidential record names the contact reference and never
 *   the third party's number. Level 2 cannot see what was written.
 */
final class EmergencyContactsTest extends TestCase
{
    use ClearsTheEvidentialLog;

    private const USER = 'cafe0001cafe0002cafe0003cafe0004';

    private const OTHER = 'cafe1001cafe1002cafe1003cafe1004';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->insertUser(self::USER, '+910000000801');
        $this->insertUser(self::OTHER, '+910000000802');
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_nomination_is_recorded_against_the_user(): void
    {
        // FRD-FR-181 / FRD-FR-184.
        $id = $this->nominate(self::USER, '+910000001001', 'Sister');

        $rows = $this->connection('mysql')->select(
            'SELECT c.external_id, c.phone_number, c.label FROM '.DatabaseEmergencyContactRepository::TABLE.' c'
            .' INNER JOIN '.DatabaseUserRepository::TABLE.' u ON u.id = c.user_id WHERE u.external_id = ?',
            [self::USER],
        );

        self::assertCount(1, $rows);
        self::assertSame($id, $rows[0]->external_id);
        self::assertSame('+910000001001', $rows[0]->phone_number);
        self::assertSame('Sister', $rows[0]->label);
    }

    public function test_a_contact_without_a_label_is_held_with_a_null_label(): void
    {
        // DB-109 ‡: NOT NULL only where a value always exists. A label does not.
        $this->nominate(self::USER, '+910000001002', null);

        $rows = $this->connection('mysql')->select(
            'SELECT label FROM '.DatabaseEmergencyContactRepository::TABLE
        );

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->label);
    }

    public function test_the_database_holds_one_number_per_user_once(): void
    {
        // UC-048 A1, enforced by the constraint rather than by the read that
        // preceded it. DB-142 ‡'s pattern: this is what decides a race, and it is
        // asserted directly because the domain check would mask it in the
        // ordinary case.
        $this->nominate(self::USER, '+910000001003', null);

        $this->expectExceptionMessageMatches('/Duplicate entry|op_user_emergency_contacts_user_number_unique/');

        $this->connection('mysql')->insert(
            'INSERT INTO '.DatabaseEmergencyContactRepository::TABLE
            .' (external_id, user_id, phone_number, label, created_at, updated_at)'
            .' SELECT ?, u.id, ?, NULL, ?, ? FROM '.DatabaseUserRepository::TABLE.' u WHERE u.external_id = ?',
            [
                str_repeat('a', 32), '+910000001003',
                '2026-08-21 12:00:00.000000', '2026-08-21 12:00:00.000000', self::USER,
            ],
        );
    }

    public function test_two_users_may_nominate_the_same_number(): void
    {
        // The constraint is per user, and it has to be: two people may both name
        // the same relative, and the platform has no view about who a contact is.
        $this->nominate(self::USER, '+910000001004', null);
        $this->nominate(self::OTHER, '+910000001004', null);

        $rows = $this->connection('mysql')->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseEmergencyContactRepository::TABLE.' WHERE phone_number = ?',
            ['+910000001004'],
        );

        self::assertSame(2, (int) $rows[0]->total);
    }

    public function test_a_second_nomination_of_the_same_number_is_refused_with_its_reason(): void
    {
        $this->nominate(self::USER, '+910000001005', null);

        $result = $this->app->make(NominateEmergencyContact::class)->execute(
            EmergencyContactCommand::toNominate($this->caller(self::USER), $this->key('dup'), '+910000001005', null),
            $this->actor(self::USER),
        );

        self::assertTrue($result->isFailure());
        self::assertSame(
            EmergencyContactRefusal::AlreadyNominated->identifier(),
            $this->refusalIdentifier($result->failure()),
        );

        // FRD-FR-024's principle: the store is unchanged by a refused write.
        self::assertSame(1, $this->contactCount());
    }

    public function test_an_amendment_replaces_the_details_and_keeps_the_row(): void
    {
        // FRD-FR-182. The external identifier is what makes it the same
        // nomination, so it survives — and DB-022 ‡ means it was never the
        // caller's to choose.
        $id = $this->nominate(self::USER, '+910000001006', 'Old');

        $this->app->make(AmendEmergencyContact::class)->execute(
            EmergencyContactCommand::toAmend(
                $this->caller(self::USER),
                $this->key('amend'),
                $id,
                '+910000001007',
                'New',
            ),
            $this->actor(self::USER),
        );

        $rows = $this->connection('mysql')->select(
            'SELECT external_id, phone_number, label, created_at, updated_at FROM '
            .DatabaseEmergencyContactRepository::TABLE
        );

        self::assertCount(1, $rows);
        self::assertSame($id, $rows[0]->external_id);
        self::assertSame('+910000001007', $rows[0]->phone_number);
        self::assertSame('New', $rows[0]->label);
    }

    public function test_another_users_contact_cannot_be_amended_or_removed(): void
    {
        // SEC-066 ‡ — a user accesses only records to which they are a party —
        // and SEC-069 ‡, which makes the answer the same one absence gets. The
        // set is loaded for the caller, so the other user's contact is simply not
        // in it.
        $theirId = $this->nominate(self::OTHER, '+910000001008', null);

        foreach ([AmendEmergencyContact::class, RemoveEmergencyContact::class] as $service) {
            $command = $service === AmendEmergencyContact::class
                ? EmergencyContactCommand::toAmend($this->caller(self::USER), $this->key('x'.$service), $theirId, '+910000009999', null)
                : EmergencyContactCommand::toRemove($this->caller(self::USER), $this->key('y'.$service), $theirId);

            $result = $this->app->make($service)->execute($command, $this->actor(self::USER));

            self::assertTrue($result->isFailure(), $service.' acted on another user\'s contact.');
            self::assertSame(
                EmergencyContactRefusal::NotNominated->identifier(),
                $this->refusalIdentifier($result->failure()),
            );
        }

        // And it is still there, unchanged.
        $rows = $this->connection('mysql')->select(
            'SELECT phone_number FROM '.DatabaseEmergencyContactRepository::TABLE
        );

        self::assertCount(1, $rows);
        self::assertSame('+910000001008', $rows[0]->phone_number);
    }

    public function test_removing_takes_the_row_and_leaves_the_evidence(): void
    {
        // RemoveEmergencyContact's note: the third party's details go, and what
        // survives is the evidential record that the user withdrew them.
        $id = $this->nominate(self::USER, '+910000001009', 'Neighbour');

        $this->app->make(RemoveEmergencyContact::class)->execute(
            EmergencyContactCommand::toRemove($this->caller(self::USER), $this->key('rm'), $id),
            $this->actor(self::USER),
        );

        self::assertSame(0, $this->contactCount());

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString(RemoveEmergencyContact::ACTION, $encoded);
        self::assertStringContainsString($id, $encoded);
    }

    public function test_the_evidential_record_never_holds_the_third_partys_number_or_label(): void
    {
        // BE-201 ‡, and the sharpest case of it in the platform. The subject of
        // this record is a person who has no account, agreed to nothing, and whom
        // UC-OQ-006 records the platform may never even tell. An evidential log
        // holding their number would be a log the user cannot ask to have
        // corrected, because DB-125 ‡ makes it append-only.
        $this->nominate(self::USER, '+910000001010', 'Mother');

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString(NominateEmergencyContact::ACTION, $encoded);
        self::assertStringNotContainsString('+910000001010', $encoded);
        self::assertStringNotContainsString('Mother', $encoded);
    }

    public function test_the_read_returns_the_set_in_the_order_it_was_built(): void
    {
        $this->nominate(self::USER, '+910000001011', 'One');
        $this->nominate(self::USER, '+910000001012', 'Two');

        $result = $this->app->make(ReadEmergencyContacts::class)->execute(
            EmergencyContactCommand::toRead($this->caller(self::USER), $this->key('read')),
            $this->actor(self::USER),
        );

        $contacts = $result->value();

        self::assertInstanceOf(ContactSetView::class, $contacts);
        self::assertSame(
            ['+910000001011', '+910000001012'],
            array_column($contacts->toArray(), 'phone_number'),
        );
    }

    public function test_the_read_returns_only_the_callers_own(): void
    {
        // SEC-066 ‡ / API-129 ‡. There is no path by which a caller names whose
        // set they mean, and this asserts that the one they get is theirs.
        $this->nominate(self::USER, '+910000001013', null);
        $this->nominate(self::OTHER, '+910000001014', null);

        $result = $this->app->make(ReadEmergencyContacts::class)->execute(
            EmergencyContactCommand::toRead($this->caller(self::USER), $this->key('read2')),
            $this->actor(self::USER),
        );

        $contacts = $result->value();

        self::assertInstanceOf(ContactSetView::class, $contacts);
        self::assertSame(
            ['+910000001013'],
            array_column($contacts->toArray(), 'phone_number'),
        );
    }

    /**
     * The identifier a failure carries, whichever branch it is.
     *
     * `API-081` ‡ makes the identifier the stable thing, so it is what these
     * tests assert on — the default text is a default, and `API-083` has the
     * client present its own.
     */
    private function refusalIdentifier(Failure $failure): string
    {
        if ($failure instanceof BusinessRefused) {
            return $failure->identifier();
        }

        if ($failure instanceof InvalidRequest) {
            $errors = $failure->fieldErrors();

            self::assertNotSame([], $errors);

            return $errors[0]->identifier();
        }

        return $failure::class;
    }

    /**
     * Nominate, and answer with the identifier the platform issued.
     *
     * The service returns a {@see ContactView} rather than the aggregate:
     * `BE-002` keeps a Domain type out of the adapter and `API-050` ‡ puts what
     * a representation discloses outside it, so the view is what crosses the
     * boundary and it is what this asserts against.
     */
    private function nominate(string $user, string $number, ?string $label): string
    {
        $result = $this->app->make(NominateEmergencyContact::class)->execute(
            EmergencyContactCommand::toNominate(
                $this->caller($user),
                $this->key($user.$number),
                $number,
                $label,
            ),
            $this->actor($user),
        );

        self::assertTrue(
            $result->isSuccess(),
            $result->isFailure() ? $this->refusalIdentifier($result->failure()) : '',
        );

        $contact = $result->value();

        self::assertInstanceOf(ContactView::class, $contact);

        return $contact->toArray()['id'];
    }

    private function caller(string $user): AuthenticatedCaller
    {
        $tokens = $this->app->make(HashesSessionTokens::class);
        $session = Session::establish(
            UserReference::fromString($user),
            $tokens->hash($tokens->generate()),
            // The platform's clock rather than a literal: SEC-039 ‡ bounds a
            // session, so a pinned fixture expires the day after it is written.
            $this->app->make(Clock::class)->now(),
        );

        $this->app->make(SessionRepository::class)->save($session);

        return new AuthenticatedCaller($session, $this->actor($user));
    }

    private function actor(string $user): Actor
    {
        return Actor::holding(ActorReference::fromString($user), []);
    }

    private function key(string $seed): IdempotencyKey
    {
        return IdempotencyKey::fromString('integration-'.substr(hash('sha256', $seed), 0, 24));
    }

    private function contactCount(): int
    {
        $rows = $this->connection('mysql')->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseEmergencyContactRepository::TABLE
        );

        return (int) $rows[0]->total;
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

    private function insertUser(string $reference, string $phone): void
    {
        $this->connection('mysql')->insert(
            'INSERT INTO '.DatabaseUserRepository::TABLE
            .' (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [
                $reference, $phone, 'VERIFIED', 'ACTIVE',
                '2026-08-21 12:00:00.000000', '2026-08-21 12:00:00.000000',
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
        // DB-215: the migration account is the only one that may delete from op_.
        $migration = $this->connection('mysql_migration');

        $migration->delete('DELETE FROM '.DatabaseEmergencyContactRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseSessionRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseUserRepository::TABLE);
        // DB-108 ‡ refuses the delete outright, so the log is put back to empty
        // through the trait — which drops the trigger under the provisioning
        // account and restores it. That the fixture has to work this hard is the
        // rule working.
        $this->clearEvidentialLog();
    }
}
