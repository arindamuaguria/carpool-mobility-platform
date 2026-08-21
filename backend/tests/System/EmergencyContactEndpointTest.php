<?php

declare(strict_types=1);

namespace Tests\System;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\NominateEmergencyContact;
use Cmp\Application\User\RemoveEmergencyContact;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Evidential\DatabaseEvidentialWriter;
use Cmp\Infrastructure\Persistence\User\DatabaseEmergencyContactRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\SessionCarriage;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\TestCase;

/**
 * `UC-048` end to end — and the prohibition that comes with it.
 *
 * **Level 5** (`TC-025`): a real HTTP request through the real middleware, the
 * real application service and a real MySQL. Two things only this level can say.
 *
 * - The **journey** works: nominate, read back, amend, remove, and what a second
 *   caller sees of it.
 * - **`FRD-FR-195` ‡ and `UX-165` ‡** are about what is *presented*, so they can
 *   only be checked against what is actually served. `UC-048` accepts the
 *   platform holding contacts *"only because nothing is sent to them"*, and a
 *   response implying otherwise would break that condition without breaking any
 *   unit test.
 */
final class EmergencyContactEndpointTest extends TestCase
{
    use ClearsTheEvidentialLog;

    private const USER = 'face0001face0002face0003face0004';

    private const OTHER = 'face1001face1002face1003face1004';

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test',
        );

        $this->insertUser(self::USER, '+910000001001');
        $this->insertUser(self::OTHER, '+910000001002');
        $this->token = $this->establish(self::USER);
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_user_nominates_reads_amends_and_removes(): void
    {
        // FRD-FR-181, FRD-FR-182, FRD-FR-184 — UC-048's main success scenario,
        // over the wire.
        $id = $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000003001', 'label' => 'Sister'])
            ->assertOk()
            ->json('data.contact.id');

        self::assertIsString($id);

        $this->send('GET', 'profile/emergency-contacts')
            ->assertOk()
            ->assertJsonPath('data.contacts.0.label', 'Sister');

        $this->send('PUT', 'profile/emergency-contacts/'.$id, ['phone_number' => '+910000003002', 'label' => 'Brother'])
            ->assertOk()
            ->assertJsonPath('data.contact.id', $id)
            ->assertJsonPath('data.contact.phone_number', '+910000003002');

        $this->send('DELETE', 'profile/emergency-contacts/'.$id)->assertOk();

        $this->send('GET', 'profile/emergency-contacts')
            ->assertOk()
            ->assertJsonPath('data.contacts', []);
    }

    public function test_more_than_one_may_be_nominated(): void
    {
        // UC-048 A1, and FRD-FR-181's "one or more".
        $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000003003'])->assertOk();
        $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000003004'])->assertOk();

        $contacts = $this->send('GET', 'profile/emergency-contacts')->json('data.contacts');

        self::assertIsArray($contacts);
        self::assertCount(2, $contacts);
    }

    public function test_another_users_contacts_are_neither_readable_nor_reachable(): void
    {
        // SEC-066 ‡ and SEC-069 ‡, end to end. The other user's contact exists,
        // and to this caller it is indistinguishable from one that does not.
        $theirToken = $this->establish(self::OTHER);

        $theirId = $this->withHeaders([
            RequireIdempotencyKey::HEADER => 'system-others-nomination',
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$theirToken,
        ])->json('POST', '/api/v1/profile/emergency-contacts', ['phone_number' => '+910000003005'])
            ->assertOk()
            ->json('data.contact.id');

        self::assertIsString($theirId);

        $this->send('GET', 'profile/emergency-contacts')->assertJsonPath('data.contacts', []);
        $this->send('DELETE', 'profile/emergency-contacts/'.$theirId)->assertStatus(409);

        // And it is still theirs.
        self::assertSame(1, $this->contactCount());
    }

    public function test_every_change_is_evidenced_and_none_of_the_records_holds_the_number(): void
    {
        // UC-048 step 4 / UC-079 / FRD-FR-248 ‡, and BE-201 ‡ over the whole
        // journey rather than one operation of it. The nominated person is not a
        // user, agreed to nothing, and UC-OQ-006 records the platform may never
        // tell them — an append-only log holding their number could not be put
        // right afterwards (DB-125 ‡).
        $id = $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000003006', 'label' => 'Mother'])
            ->json('data.contact.id');

        self::assertIsString($id);

        $this->send('PUT', 'profile/emergency-contacts/'.$id, ['phone_number' => '+910000003007', 'label' => 'Father']);
        $this->send('DELETE', 'profile/emergency-contacts/'.$id);

        $encoded = json_encode($this->evidentialRows(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString(NominateEmergencyContact::ACTION, $encoded);
        self::assertStringContainsString(RemoveEmergencyContact::ACTION, $encoded);

        foreach (['+910000003006', '+910000003007', 'Mother', 'Father'] as $detail) {
            self::assertStringNotContainsString($detail, $encoded, 'BE-201 ‡: the record names the nomination, never the person.');
        }
    }

    /**
     * `FRD-FR-195` ‡ / `UX-165` ‡ / `UX-176` ‡ / `NFR-137`, against what is
     * actually served.
     *
     * *"The system shall not present a safety control that the platform cannot
     * honour."* `BAD-DEC-011` is open, no response capability is staffed, and
     * `FRD-GAP-020` blocks every part of whether and when a contact is informed.
     * So nothing this surface returns may state or imply that anybody will be
     * reached, that help is on the way, or that the platform provides protection.
     *
     * The check is on the **served bytes**, because that is what a user reads. A
     * reviewer could satisfy themselves by reading the controller; a build
     * cannot, and this is the form that a build can.
     */
    public function test_nothing_the_surface_serves_implies_that_anybody_will_be_reached(): void
    {
        $id = $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000003008'])
            ->json('data.contact.id');

        self::assertIsString($id);

        $served = implode("\n", [
            (string) $this->send('GET', 'profile/emergency-contacts')->getContent(),
            (string) $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000003009'])->getContent(),
            (string) $this->send('PUT', 'profile/emergency-contacts/'.$id, ['phone_number' => '+910000003010'])->getContent(),
            (string) $this->send('DELETE', 'profile/emergency-contacts/'.$id)->getContent(),
            // The refusals too. A refusal is presented text, and "we could not
            // alert them" would imply an alert exists.
            (string) $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000003009'])->getContent(),
            (string) $this->send('DELETE', 'profile/emergency-contacts/'.str_repeat('b', 32))->getContent(),
        ]);

        $forbidden = [
            'notified', 'notify', 'alerted', 'alert', 'informed', 'inform',
            'emergency service', 'help is', 'on the way', 'assistance',
            'protected', 'protection', 'rescue', 'dispatch', 'responder',
            'we will contact', 'sos',
        ];

        foreach ($forbidden as $claim) {
            self::assertStringNotContainsStringIgnoringCase(
                $claim,
                $served,
                sprintf(
                    'FRD-FR-195 ‡ / UX-165 ‡: the surface served "%s". BAD-DEC-011 is open, nothing is sent to a '
                    .'nominated contact, and UC-048 holds contacts only on that condition.',
                    $claim,
                ),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function send(string $method, string $path, array $body = []): TestResponse
    {
        return $this->withHeaders([
            RequireIdempotencyKey::HEADER => 'system-'.substr(hash('sha256', $method.$path.serialize($body)), 0, 20),
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$this->token,
        ])->json($method, '/api/v1/'.$path, $body);
    }

    private function establish(string $reference): string
    {
        $tokens = $this->app->make(HashesSessionTokens::class);
        $token = $tokens->generate();

        $this->app->make(SessionRepository::class)->save(Session::establish(
            UserReference::fromString($reference),
            $tokens->hash($token),
            $this->app->make(Clock::class)->now(),
        ));

        return $token;
    }

    private function contactCount(): int
    {
        $rows = $this->connection('mysql')->select(
            'SELECT COUNT(*) AS total FROM '.DatabaseEmergencyContactRepository::TABLE
        );

        return (int) $rows[0]->total;
    }

    /**
     * @return list<object{actor: string, action: string, subject: string, reason: ?string}>
     */
    private function evidentialRows(): array
    {
        /** @var list<object{actor: string, action: string, subject: string, reason: ?string}> $rows */
        $rows = $this->connection('mysql')->select(
            'SELECT actor, action, subject, reason FROM '.DatabaseEvidentialWriter::TABLE.' ORDER BY id ASC'
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
        $migration = $this->connection('mysql_migration');

        $migration->delete('DELETE FROM '.DatabaseEmergencyContactRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseSessionRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseUserRepository::TABLE);

        $this->clearEvidentialLog();
    }
}
