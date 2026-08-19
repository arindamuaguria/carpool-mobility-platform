<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\TestCase;

/**
 * `CMP-IMP-467` — JSON only, one representation per resource.
 *
 * `API-011`: *"no other media type shall be negotiated."* `AADR-05` gives the
 * reason — one representation per resource leaves nothing to negotiate, and a
 * negotiation mechanism with one possible answer is a mechanism everybody has to
 * reason about and nobody maintains.
 *
 * Level 4 (`TC-031`): shape only.
 */
final class MediaTypeTest extends TestCase
{
    public function test_the_surface_serves_json(): void
    {
        $response = $this->getJson('/api/v1/versions');

        $response->assertOk();
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_a_request_accepting_only_another_media_type_is_refused(): void
    {
        // Negative test for API-011. Serving JSON to a client that asked for XML
        // would honour the letter and abandon the point: the client would carry
        // on believing negotiation works.
        $response = $this->get('/api/v1/versions', ['Accept' => 'application/xml']);

        $response->assertStatus(400);
        $response->assertJsonPath('invalid_request.fields.0.field', 'Accept');
    }

    public function test_a_request_accepting_anything_is_served(): void
    {
        // `*/*` asks for nothing the platform does not serve, so it is not a
        // refusal. Neither is an absent Accept header.
        $this->get('/api/v1/versions', ['Accept' => '*/*'])->assertOk();
        $this->get('/api/v1/versions')->assertOk();
    }

    public function test_a_media_type_with_parameters_is_the_same_media_type(): void
    {
        // API-017: "the interface shall not depend on request or response header
        // order, casing or optional whitespace for meaning."
        $this->get('/api/v1/versions', ['Accept' => 'APPLICATION/JSON; charset=utf-8'])->assertOk();
    }

    public function test_a_media_type_refusal_is_an_invalid_request_and_not_another_branch(): void
    {
        // §8.6: "field missing, malformed, or of the wrong type" is an invalid
        // request because the caller can correct it. A wrong Accept header is
        // correctable, so it is not a refusal, an unavailability or a fault.
        $body = $this->get('/api/v1/versions', ['Accept' => 'text/html'])->json();

        self::assertIsArray($body);
        self::assertArrayHasKey('invalid_request', $body);

        foreach (['refusal', 'unavailable', 'fault'] as $other) {
            self::assertArrayNotHasKey($other, $body);
        }
    }

    public function test_the_refusal_still_carries_the_response_markings(): void
    {
        // API-022 and API-043 ‡ say every response, and a middleware refusal is
        // as much a response as a controller's.
        $response = $this->get('/api/v1/versions', ['Accept' => 'text/html']);

        $response->assertJsonPath('meta.interface_version', 1);
        self::assertIsString($response->json('meta.evaluated_at'));
    }
}
