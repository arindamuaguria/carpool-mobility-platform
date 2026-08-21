<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\EmergencyContactRefusal;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Persistence\User\DatabaseEmergencyContactRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\SessionCarriage;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `/profile/emergency-contacts` — the shape of it.
 *
 * **Level 4** (`TC-025`, `TC-031`): shape, never business behaviour. That a
 * duplicate is refused is `UC-048` A1's and is proved at levels 2 and 3; what is
 * here is that the refusal **looks** like a refusal, that the representation
 * carries what `API-036` ‡ permits and nothing else, and that `API-038` ‡'s
 * whole-request refusal reaches a caller through the platform's first request
 * schema.
 */
final class EmergencyContactsTest extends TestCase
{
    private const USER = 'beef0001beef0002beef0003beef0004';

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();
        $this->app->make(ChangePolicyValue::class)->apply(
            ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test',
        );

        $this->insertUser(self::USER, '+910000000901');
        $this->token = $this->establish(self::USER);
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_nomination_answers_with_the_contact(): void
    {
        $response = $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000002001', 'label' => 'Sister']);

        $response->assertOk();
        $response->assertJsonPath('data.contact.phone_number', '+910000002001');
        $response->assertJsonPath('data.contact.label', 'Sister');
        $response->assertJsonPath('meta.interface_version', 1);

        // DB-022 ‡ / DB-024 ‡: the identifier is the external one, and it is
        // thirty-two hexadecimal characters rather than anything a caller could
        // have counted up to.
        $id = $response->json('data.contact.id');

        self::assertIsString($id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function test_the_representation_carries_three_fields_and_no_more(): void
    {
        // API-036 ‡: a representation carries no field by which a caller may
        // assert something the platform decides. There is also nothing here
        // about whether anybody would be reached — FRD-GAP-020.
        $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000002002'])->assertOk();

        $contacts = $this->send('GET', 'profile/emergency-contacts')->json('data.contacts');

        self::assertIsArray($contacts);
        self::assertCount(1, $contacts);

        $first = $contacts[0];

        self::assertIsArray($first);
        self::assertSame(['id', 'phone_number', 'label'], array_keys($first));
    }

    public function test_an_absent_label_is_served_as_null_rather_than_omitted(): void
    {
        // A client reads a field it can rely on being there. Omitting it would
        // make "no label" and "the server did not say" the same value.
        $response = $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000002003']);

        $response->assertOk();
        $response->assertJsonPath('data.contact.label', null);
    }

    public function test_a_field_the_schema_does_not_define_refuses_the_whole_request(): void
    {
        // API-038 ‡, through the platform's first RequestSchema. The nomination
        // does not half-happen: the refusal is the whole answer.
        $response = $this->send('POST', 'profile/emergency-contacts', [
            'phone_number' => '+910000002004',
            'nickname' => 'Sis',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.field', 'nickname');
        $response->assertJsonPath('invalid_request.fields.0.reason', 'request.field_not_defined');

        self::assertSame(0, $this->contactCount());
    }

    public function test_an_authoritative_value_in_the_body_is_refused_before_the_schema_sees_it(): void
    {
        // FRD-FR-238 ‡ / API-037 ‡. RefuseAssertedAuthority runs on the outer
        // group, so this is refused with the authoritative-value reason rather
        // than as an undefined field — the two branches are distinguishable, and
        // API-039 ‡ records only this one.
        $response = $this->send('POST', 'profile/emergency-contacts', [
            'phone_number' => '+910000002005',
            'verificationStatus' => 'VERIFIED',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.field', 'verification standing');
        $response->assertJsonPath('invalid_request.fields.0.reason', 'request.authoritative_value_asserted');
    }

    public function test_an_unusable_number_is_reported_with_the_reason_it_is_unusable(): void
    {
        // FRD-FR-183 / API-131 / UX-161, in the invalid-request branch — API-087
        // keeps what the caller can correct distinct from a business refusal.
        $response = $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+91 000000 2008']);

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.field', 'phone_number');
        $response->assertJsonPath('invalid_request.fields.0.reason', 'contact.phone_number_unusable');
    }

    public function test_a_blank_number_arrives_as_absent_rather_than_unusable(): void
    {
        // Recorded rather than assumed. Laravel applies TrimStrings and
        // ConvertEmptyStringsToNull by default, so a caller sending `"   "`
        // reaches the platform as `null` and is answered "absent" rather than
        // "unusable".
        //
        // FRD-FR-183 is met either way — both state why the contact cannot be
        // recorded — and this is asserted so the normalisation is a known
        // property of the surface instead of something a later reader discovers
        // by being surprised. PhoneNumber still refuses a blank value; nothing
        // depends on the framework doing it first.
        $response = $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '   ']);

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.reason', 'contact.phone_number_absent');
    }

    public function test_an_absent_number_is_reported_as_absent(): void
    {
        $response = $this->send('POST', 'profile/emergency-contacts', ['label' => 'Nobody']);

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.reason', 'contact.phone_number_absent');
    }

    public function test_both_unusable_fields_are_reported_at_once(): void
    {
        // API-079: every detectable failure, not the first, so a caller
        // correcting two mistakes does it once.
        $response = $this->send('POST', 'profile/emergency-contacts', [
            'phone_number' => 'has a space',
            'label' => str_repeat('x', 100),
        ]);

        $response->assertStatus(400);

        $fields = $response->json('invalid_request.fields');

        self::assertIsArray($fields);
        self::assertSame(['phone_number', 'label'], array_column($fields, 'field'));
    }

    public function test_a_duplicate_is_a_business_refusal_and_not_an_invalid_request(): void
    {
        // API-072 ‡ / API-087: the branches are distinguishable by structure
        // alone. This one is decided against platform state, so it is 409 with a
        // `refusal`, not 400 with `invalid_request`.
        $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000002006'])->assertOk();

        $response = $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000002006']);

        $response->assertStatus(409);
        $response->assertJsonPath('refusal.reason', EmergencyContactRefusal::AlreadyNominated->value);
        $response->assertJsonMissingPath('invalid_request');
    }

    public function test_a_reference_naming_nothing_is_refused_the_same_way_however_it_is_wrong(): void
    {
        // SEC-069 ‡ / API-094 ‡. A well-formed reference nobody holds and a value
        // that is not a reference at all get the same identifier, so a caller
        // cannot tell the identifier space from the refusals.
        $wellFormed = $this->send('DELETE', 'profile/emergency-contacts/'.str_repeat('a', 32));
        $malformed = $this->send('DELETE', 'profile/emergency-contacts/not-a-reference');

        self::assertSame(
            EmergencyContactRefusal::NotNominated->value,
            $wellFormed->json('refusal.reason') ?? $wellFormed->json('invalid_request.fields.0.reason'),
        );

        self::assertSame(
            EmergencyContactRefusal::NotNominated->value,
            $malformed->json('refusal.reason') ?? $malformed->json('invalid_request.fields.0.reason'),
        );
    }

    public function test_a_removal_answers_that_it_removed(): void
    {
        $id = $this->send('POST', 'profile/emergency-contacts', ['phone_number' => '+910000002007'])
            ->json('data.contact.id');

        self::assertIsString($id);

        $response = $this->send('DELETE', 'profile/emergency-contacts/'.$id);

        $response->assertOk();
        $response->assertJsonPath('data.removed', true);
    }

    public function test_every_operation_requires_a_session(): void
    {
        // API-095 ‡: CMP-DOC-10 §9.1 names the five reachable without one, and
        // none of these is among them.
        foreach ([['GET', ''], ['POST', ''], ['PUT', '/'.str_repeat('a', 32)], ['DELETE', '/'.str_repeat('a', 32)]] as [$method, $suffix]) {
            $this->withHeaders([RequireIdempotencyKey::HEADER => 'contact-anon-'.$method])
                ->json($method, '/api/v1/profile/emergency-contacts'.$suffix)
                ->assertStatus(409);
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function send(string $method, string $path, array $body = []): TestResponse
    {
        return $this->withHeaders([
            // API-057 ‡. Unique per call, because API-062 ‡ would otherwise
            // replay the first outcome and these tests ask a second question.
            RequireIdempotencyKey::HEADER => 'contact-'.substr(hash('sha256', $method.$path.serialize($body)), 0, 20),
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
    }
}
