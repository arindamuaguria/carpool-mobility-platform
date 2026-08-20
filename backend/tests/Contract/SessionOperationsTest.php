<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Application\User\SessionRefusal;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\SessionCarriage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `CMP-IMP-056`, `CMP-IMP-057` — the shape of the two session operations.
 *
 * **Level 4** (`TC-031`): *shape, never business behaviour.* Nothing here needs a
 * session to exist, because every case sends a request that is refused before
 * the store is consulted — which is the whole of what a contract test can say
 * about an authenticated operation. What the operations **do** is level 5's, in
 * `Tests\System\SessionEndpointTest`.
 *
 * The shape being asserted is `API-095` ‡'s: an operation outside CMP-DOC-10
 * §9.1 answers an unauthenticated caller with a business refusal, in the envelope
 * every response on this surface has.
 */
final class SessionOperationsTest extends TestCase
{
    /**
     * The two operations, by method and path.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function operations(): array
    {
        return [
            'terminate' => ['DELETE', '/api/v1/sessions/current'],
            'refresh' => ['POST', '/api/v1/sessions/current/refresh'],
        ];
    }

    #[DataProvider('operations')]
    public function test_an_operation_without_a_session_is_refused(string $method, string $path): void
    {
        // API-095 ‡. API-087 maps a state conflict to 409, and SessionRefusal is
        // one — a caller who signs in again can proceed, which is a state that
        // changes rather than a rule that declined.
        $response = $this->json($method, $path, [], $this->idempotent());

        $response->assertStatus(409);
        $response->assertJsonPath('refusal.reason', SessionRefusal::NotUsable->value);
        $response->assertJsonPath('refusal.default_text', SessionRefusal::NotUsable->defaultText());
    }

    #[DataProvider('operations')]
    public function test_the_refusal_carries_the_envelope_every_response_carries(string $method, string $path): void
    {
        // API-022 / API-043 ‡: a failure is still a response, so it still states
        // the version that served it and when the platform evaluated it. A
        // client that reads `meta` reads it the same way on both paths.
        $response = $this->json($method, $path, [], $this->idempotent());

        $response->assertJsonPath('meta.interface_version', 1);
        $response->assertJsonPath('meta.deprecated', false);
        $response->assertJsonStructure(['meta' => ['evaluated_at'], 'refusal' => ['reason', 'default_text']]);
    }

    #[DataProvider('operations')]
    public function test_the_refusal_uses_the_business_branch_and_no_other(string $method, string $path): void
    {
        // API-072 ‡: the four branches are distinguishable by structure alone. A
        // client can tell which branch this is without reading a discriminator,
        // so the other three top-level keys must be absent — and `data` with
        // them, because a failure carries none.
        $response = $this->json($method, $path, [], $this->idempotent());

        foreach (['invalid_request', 'unavailable', 'fault', 'data'] as $absent) {
            $response->assertJsonMissingPath($absent);
        }
    }

    #[DataProvider('operations')]
    public function test_an_unauthenticated_refusal_discloses_nothing_about_the_token(string $method, string $path): void
    {
        // SEC-048 ‡ / API-103 ‡: absent, malformed, unknown, terminated and
        // expired are one answer. Three shapes of nonsense token, and a request
        // with no header at all, produce the identical body — so a caller learns
        // nothing from the refusal about whether the token was ever real.
        $bodies = [];

        foreach ([null, 'Bearer not-a-real-token', 'Bearer ', 'Basic abcdef'] as $header) {
            $headers = $this->idempotent();

            if ($header !== null) {
                $headers[SessionCarriage::REQUEST_HEADER] = $header;
            }

            $response = $this->json($method, $path, [], $headers);
            $response->assertStatus(409);

            $decoded = $response->json();
            self::assertIsArray($decoded);
            unset($decoded['meta']);
            $bodies[] = $decoded;
        }

        self::assertCount(1, array_unique(array_map(
            static fn (array $body): string => json_encode($body, JSON_THROW_ON_ERROR),
            $bodies,
        )));
    }

    #[DataProvider('operations')]
    public function test_a_state_changing_operation_still_requires_an_idempotency_key(string $method, string $path): void
    {
        // API-057 ‡ / API-058 ‡. The key is required by the outer group, so it
        // is demanded before the session is looked at — and a caller missing
        // both is told about the key, which is the correctable one. That
        // ordering is AADR-04's mandatory key holding even on the failure path.
        $response = $this->json($method, $path);

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.field', RequireIdempotencyKey::HEADER);
    }

    public function test_neither_operation_is_reachable_without_the_version_prefix(): void
    {
        // API-019 ‡: the version is the first path segment, and there is no
        // unversioned alias. A client cannot end up on an unversioned surface
        // whose behaviour nothing states.
        foreach (['/sessions/current', '/api/sessions/current'] as $path) {
            $this->json('DELETE', $path, [], $this->idempotent())->assertNotFound();
        }
    }

    /**
     * A request that satisfies the outer group, so the case under test is the
     * one that answers.
     *
     * @return array<string, string>
     */
    private function idempotent(): array
    {
        return [RequireIdempotencyKey::HEADER => 'contract-'.__CLASS__];
    }
}
