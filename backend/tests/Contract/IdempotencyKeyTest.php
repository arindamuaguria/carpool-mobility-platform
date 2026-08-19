<?php

declare(strict_types=1);

namespace Tests\Contract;

use Cmp\Application\Shared\Response\EvaluationTime;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * `CMP-IMP-436` — every state-changing request carries an idempotency key.
 *
 * `API-057` ‡ and `API-058` ‡, and §8.6 places the refusal in the invalid-request
 * branch because the caller can correct it.
 *
 * Level 4 (`TC-031`): presence of a header is shape. What the registry then does
 * with the key — replay on a match (`API-062` ‡), refuse on a collision
 * (`API-063` ‡) — is behaviour, tested at level 3 in `IdempotencyRegistryTest`
 * where a real transaction exists to test it against.
 *
 * ## Why the middleware is exercised directly
 *
 * The surface serves one operation and it is a `GET`, so there is no
 * state-changing route to send. Sending `POST` to the `GET` path does not reach
 * the middleware either: the framework answers `405` while resolving the route,
 * before route middleware runs.
 *
 * That `405` is worth naming rather than working around. `API-071` ‡ requires
 * every **failure** to be one of four branches, and a request naming no operation
 * is not an operation failing — the same reading that makes an unrouted path a
 * `404` rather than a business refusal. `API-094` ‡'s indistinguishability
 * concerns a **resource** the caller may or may not be entitled to, and a method
 * the platform does not route is neither. Recorded as an observation; if the
 * Project Owner reads `API-071` ‡ as covering these, the surface needs a fallback
 * handler and this note is where that decision would attach.
 */
final class IdempotencyKeyTest extends TestCase
{
    public function test_a_get_needs_no_key(): void
    {
        // API-065 / API-007: GET is safe and changes no authoritative state, so
        // there is nothing for a key to guard. Through the real surface, because
        // that is the case the surface can actually serve.
        $this->getJson('/api/v1/versions')->assertOk();
    }

    public function test_a_state_changing_request_without_a_key_is_refused(): void
    {
        // Negative test for API-057 ‡ / API-058 ‡.
        $response = $this->pass(self::request('POST'));

        self::assertSame(400, $response->getStatusCode());

        self::assertSame(
            [[
                'field' => RequireIdempotencyKey::HEADER,
                'reason' => 'idempotency.key_required',
                'default_text' => 'Every state-changing request must carry an idempotency key.',
            ]],
            self::section($response, 'invalid_request')['fields'],
        );
    }

    public function test_an_empty_key_is_the_same_as_no_key(): void
    {
        // A header sent as whitespace is a client bug that would otherwise
        // produce a registry entry keyed on nothing.
        self::assertSame(400, $this->pass(self::request('POST', '   '))->getStatusCode());
    }

    public function test_the_refusal_is_an_invalid_request_and_not_a_business_refusal(): void
    {
        // §8.6 is explicit: "Idempotency key absent → Invalid request". The
        // neighbouring row — "key reused with different content" — is a business
        // refusal, and confusing the two would tell a client to correct something
        // it cannot, or to give up on something it could retry.
        $body = self::body($this->pass(self::request('POST')));

        self::assertArrayHasKey('invalid_request', $body);
        self::assertArrayNotHasKey('refusal', $body);
    }

    public function test_every_state_changing_method_requires_a_key(): void
    {
        // API-008 makes PUT and DELETE idempotent "by definition of the
        // operation, independently of the idempotency key" — a different
        // guarantee, and not a substitute, because API-062 ‡ replays from a
        // registry entry that only a key produces.
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertSame(
                400,
                $this->pass(self::request($method))->getStatusCode(),
                $method.' must require an idempotency key.',
            );
        }
    }

    public function test_no_safe_method_requires_a_key(): void
    {
        // API-065 / API-007, and HEAD and OPTIONS follow GET for the same reason.
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            self::assertSame(
                204,
                $this->pass(self::request($method))->getStatusCode(),
                $method.' changes no authoritative state and must not require a key.',
            );
        }
    }

    public function test_a_key_is_accepted_without_being_parsed(): void
    {
        // API-059: caller-generated and opaque to the platform. Nothing in the
        // middleware reads its shape, so a key that is not a UUID passes exactly
        // as one that is — the platform has no format to impose and imposing one
        // would be a decision MOB-049 leaves to the client.
        foreach (['anything-at-all', '0', 'ab', str_repeat('k', 200)] as $key) {
            self::assertSame(204, $this->pass(self::request('POST', $key))->getStatusCode());
        }
    }

    public function test_the_requirement_is_a_property_of_the_surface_and_not_of_a_route(): void
    {
        // AADR-04 made the key mandatory. A per-route opt-in is how "mandatory"
        // becomes "usually", so the middleware is registered on the group and
        // this asserts it stays there — once, so a second registration cannot
        // drift from the first.
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/api.php');
        self::assertIsString($routes);

        self::assertSame(1, substr_count($routes, 'RequireIdempotencyKey::class'));
    }

    /**
     * Runs the middleware, with a next that answers `204` so the status says
     * whether the request got through.
     */
    private function pass(Request $request): Response
    {
        $middleware = new RequireIdempotencyKey($this->app->make(EvaluationTime::class));

        return $middleware->handle($request, static fn (): Response => new Response('', 204));
    }

    private static function request(string $method, ?string $key = null): Request
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($key !== null) {
            $headers['HTTP_'.str_replace('-', '_', strtoupper(RequireIdempotencyKey::HEADER))] = $key;
        }

        return Request::create('/api/v1/anything', $method, [], [], [], $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * One top-level object of a response body, asserted to be one.
     *
     * @return array<string, mixed>
     */
    private static function section(Response $response, string $key): array
    {
        $body = self::body($response);

        self::assertArrayHasKey($key, $body);
        self::assertIsArray($body[$key]);

        /** @var array<string, mixed> $section */
        $section = $body[$key];

        return $section;
    }
}
