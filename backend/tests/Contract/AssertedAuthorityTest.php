<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Application\Shared\Integrity\AuthoritativeValues;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `FRD-FR-238`–`FRD-FR-239` ‡ — the shape of the refusal when a caller asserts
 * what the platform decides.
 *
 * **Level 4** (`TC-031`): shape, never business behaviour. That the attempt is
 * **recorded** is `FRD-FR-240` ‡'s and needs the store, so it is asserted at
 * level 5 in `Tests\System\AssertedAuthorityRecordingTest`.
 *
 * `CLAUDE.md` rule 4 states the whole of it in one line — *reject the whole
 * request, never partially apply* — and `AADR-06` says why the alternative was
 * rejected: a client that set a field and saw the request succeed believes it
 * set something.
 */
final class AssertedAuthorityTest extends TestCase
{
    /**
     * One spelling per authoritative value, and the value it asserts.
     *
     * `API-037` ‡ names seven. Every one is exercised, because a guard that
     * caught six would leave the seventh assertable and nobody would notice
     * until it mattered.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function assertions(): array
    {
        return [
            'fare' => ['fare', 'fare'],
            'verification standing' => ['verificationStatus', 'verification standing'],
            'payment status' => ['paymentStatus', 'payment status'],
            'seat counts' => ['seatsAvailable', 'seat counts'],
            'ratings' => ['ratingAverage', 'ratings'],
            'balances' => ['walletBalance', 'balances'],
            'trip state' => ['tripState', 'trip state'],
        ];
    }

    #[DataProvider('assertions')]
    public function test_a_request_asserting_an_authoritative_value_is_refused(string $field, string $value): void
    {
        // FRD-FR-238 ‡ / API-038 ‡. The invalid-request branch, because the
        // caller sent something they may correct — API-087 keeps that distinct
        // from a business refusal.
        $response = $this->json('DELETE', '/api/v1/sessions/current', [$field => 'anything'], $this->idempotent());

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.field', $value);
        $response->assertJsonPath('invalid_request.fields.0.reason', 'request.authoritative_value_asserted');
    }

    public function test_the_refusal_names_the_canonical_value_and_not_the_spelling(): void
    {
        // BE-201 ‡ and AuthoritativeValues' own note: detection is broad and the
        // record is narrow. A caller writing `seatsRemaining` is asserting seat
        // counts, and that is what comes back — never their spelling, and never
        // the value they sent.
        $response = $this->json(
            'DELETE',
            '/api/v1/sessions/current',
            ['seatsRemaining' => 41, 'walletBalance' => '9999.99'],
            $this->idempotent(),
        );

        $response->assertStatus(400);

        $fields = $response->json('invalid_request.fields');

        self::assertIsArray($fields);
        self::assertSame(['seat counts', 'balances'], array_column($fields, 'field'));

        // API-079 reports all detectable failures, not only the first — two
        // asserted values are two entries.
        $body = (string) $response->getContent();

        self::assertStringNotContainsString('seatsRemaining', $body);
        self::assertStringNotContainsString('9999.99', $body);
    }

    public function test_the_operation_never_runs(): void
    {
        // FRD-FR-239 ‡: "in its entirety, and shall not partially apply it." The
        // request carries no session, so reaching the operation would produce a
        // 409 session refusal — a 400 means the middleware answered and nothing
        // downstream saw the request at all.
        $response = $this->json('DELETE', '/api/v1/sessions/current', ['fare' => 1], $this->idempotent());

        $response->assertStatus(400);
        $response->assertJsonMissingPath('refusal');
    }

    public function test_a_value_asserted_in_a_query_string_is_refused_too(): void
    {
        // FRD-FR-238 ‡ names no carriage. A guard reading only the body is one
        // somebody routes around by moving the field.
        $this->getJson('/api/v1/configuration?fare=1')->assertStatus(400);
    }

    public function test_a_value_asserted_inside_a_nested_object_is_refused(): void
    {
        // The shape a real client would actually send. A top-level-only check
        // would miss it.
        $response = $this->json(
            'DELETE',
            '/api/v1/sessions/current',
            ['booking' => ['detail' => ['fare' => 100]]],
            $this->idempotent(),
        );

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.field', 'fare');
    }

    public function test_an_ordinary_unknown_field_is_not_treated_as_an_assertion(): void
    {
        // API-039 ‡ records only where a name matches a known authoritative
        // value. A typo is a mistake, and treating every one as an integrity
        // event would make the record useless for the case NFR-069 cares about.
        //
        // So this passes the middleware and meets the session guard instead —
        // 409, not 400.
        $this->json('DELETE', '/api/v1/sessions/current', ['nonsense' => 1], $this->idempotent())
            ->assertStatus(409);
    }

    public function test_every_authoritative_value_has_a_case_here(): void
    {
        // A provider that covered six of the seven would pass and leave one
        // assertable. Asserted against the register rather than against a list
        // this file keeps in step by hand.
        self::assertSame(
            array_keys(AuthoritativeValues::all()),
            array_column(self::assertions(), 1),
        );
    }

    /**
     * @return array<string, string>
     */
    private function idempotent(): array
    {
        return [RequireIdempotencyKey::HEADER => 'contract-asserted-authority'];
    }
}
