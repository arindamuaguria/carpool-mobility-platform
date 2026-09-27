<?php

declare(strict_types=1);

namespace Tests\System;

use Cmp\Application\Shared\Policy\ChangePolicyValue;
use Cmp\Application\User\HashesSessionTokens;
use Cmp\Application\User\ResolveSession;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\UserReference;
use Cmp\Infrastructure\Persistence\Safety\DatabaseSafetyIncidentRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseSessionRepository;
use Cmp\Infrastructure\Persistence\User\DatabaseUserRepository;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\SessionCarriage;
use Cmp\Interface\Safety\SafetySurface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Integration\Evidence\ClearsTheEvidentialLog;
use Tests\InterfaceObligations;
use Tests\TestCase;

/**
 * `BE-191` ‡ and `API-170` ‡ — a deployment that serves **only** safety.
 *
 * - **`BE-191` ‡**: *"Safety endpoints shall be bootable as a separate entry point
 *   sharing the same code."*
 * - **`API-170` ‡**: the safety surface shall be *"specifiable and servable by a
 *   deployment implementing only these operations"*.
 *
 * ## Why this was worth writing
 *
 * `API-170` ‡ was recorded in {@see InterfaceObligations} as enforced by
 * `SafetySurfaceRulesTest::test_no_safety_operation_is_declared_on_the_general_surface`.
 * That test proves something true and something else: **no safety operation is
 * declared on the general surface** is a necessary condition for a safety-only
 * deployment and is not the same statement. A `routes/safety.php` that reached a
 * middleware alias registered in `routes/api.php`, or a controller whose
 * dependency only the general surface's provider bound, would satisfy it exactly
 * and leave a safety-only deployment unable to serve a single request.
 *
 * `AADR-11` is why the difference matters operationally: the whole point of the
 * separation is that safety can be deployed, scaled and protected on its own. A
 * separation that could not actually be deployed on its own is a comment.
 *
 * `CC-056` records the correction.
 *
 * ## How it is proven
 *
 * By building a **second application** from the same code with only
 * `routes/safety.php` registered — no `routes/api.php`, no console routes, no web
 * routes — and serving a real request through it. `BADR-16` requires the surface to
 * use *"the same application services, the same domain and the same store"*, so
 * the providers are the platform's own and nothing here is a double.
 *
 * The negative half is asserted in the same application: `GET /api/v1/health` is
 * **not found**, because this deployment does not implement it. Without that, the
 * test would pass just as well against an application that had loaded everything.
 */
final class SafetyOnlyDeploymentTest extends TestCase
{
    use ClearsTheEvidentialLog;

    private const RAISER = 'ace10001ace10002ace10003ace10004';

    private string $token = '';

    /**
     * The separate entry point `BE-191` ‡ asks for, built from the same code.
     *
     * This is the one thing in the suite that does not come from
     * `bootstrap/app.php`: the point is to show that the safety surface does not
     * need it. Providers come from `bootstrap/providers.php` as they do everywhere
     * else, which is what *"sharing the same code"* means.
     */
    public function createApplication(): Application
    {
        $root = dirname(__DIR__, 2);

        $app = Application::configure(basePath: $root)
            ->withRouting(then: static function () use ($root): void {
                Route::middleware('api')->group($root.'/routes/safety.php');
            })
            // The framework's own `api` group, which is what `routes/safety.php`
            // asks for by name. Declared here for the same reason
            // `bootstrap/app.php` declares it: the group is a property of the
            // application, not of the general surface's route file.
            ->withMiddleware(static function (Middleware $middleware): void {
                //
            })
            ->withExceptions(static function (Exceptions $exceptions): void {
                // API-174: the same four-branch error model, rendered the same way.
                $exceptions->shouldRenderJsonWhen(
                    static fn (Request $request): bool => $request->is(SafetySurface::PREFIX.'/*')
                        || $request->expectsJson(),
                );
            })
            ->create();

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearAll();

        // SEC-039 ‡'s lifetime is a policy value, and a session cannot resolve
        // without it. A safety-only deployment reads the same store.
        $this->app->make(ChangePolicyValue::class)->apply(ResolveSession::LIFETIME_KEY, '86400', 'operator-under-test');

        $this->insertRaiser();
        $this->token = $this->establish();
    }

    protected function tearDown(): void
    {
        $this->clearAll();

        parent::tearDown();
    }

    public function test_a_deployment_serving_only_safety_records_an_incident(): void
    {
        // BE-191 ‡ / API-170 ‡. The whole statement: this application registers
        // routes/safety.php and nothing else, and the incident is recorded.
        $response = $this->withHeaders([
            RequireIdempotencyKey::HEADER => 'safety-only-raise',
            SessionCarriage::REQUEST_HEADER => SessionCarriage::SCHEME.' '.$this->token,
        ])->json('POST', '/'.SafetySurface::PREFIX.'/v'.SafetySurface::CURRENT.'/incidents');

        $response->assertOk();

        $rows = $this->connection('mysql')->select(
            'SELECT external_id FROM '.DatabaseSafetyIncidentRepository::TABLE
        );

        self::assertCount(1, $rows, 'FRD-FR-185 ‡: the signal became a record, in a deployment that serves only this.');
    }

    public function test_the_general_surface_is_absent_from_that_deployment(): void
    {
        // The half that makes the test above mean something. If this deployment
        // answered /api/v1 as well, it would be the ordinary application and would
        // say nothing about API-170 ‡.
        $this->json('GET', '/api/v1/health')->assertNotFound();
        $this->json('GET', '/api/v1/versions')->assertNotFound();
        $this->json('GET', '/api/v1/profile/emergency-contacts')->assertNotFound();
    }

    public function test_the_safety_middleware_stack_resolves_without_the_general_surface(): void
    {
        // BADR-16: the same middleware, not a copy. Each is a class-string resolved
        // from the container, so a stack that depended on an alias the general
        // surface registered would fail here rather than in production — and
        // version refusal is the first of them (API-024 ‡), which is why an
        // unserved version is the request that exercises the whole stack.
        $this->withHeaders([RequireIdempotencyKey::HEADER => 'safety-only-version'])
            ->json('POST', '/'.SafetySurface::PREFIX.'/v99/incidents')
            ->assertStatus(426);

        // And the idempotency requirement, which is the middleware after it —
        // `API-057` ‡ on a state-changing operation, refused as a state conflict
        // (`API-087`) like anywhere else on the platform.
        $this->json('POST', '/'.SafetySurface::PREFIX.'/v'.SafetySurface::CURRENT.'/incidents')
            ->assertStatus(409);
    }

    private function establish(): string
    {
        $tokens = $this->app->make(HashesSessionTokens::class);
        $token = $tokens->generate();

        $this->app->make(SessionRepository::class)->save(Session::establish(
            UserReference::fromString(self::RAISER),
            $tokens->hash($token),
            $this->app->make(Clock::class)->now(),
        ));

        return $token;
    }

    private function insertRaiser(): void
    {
        $this->connection('mysql')->insert(
            'INSERT INTO '.DatabaseUserRepository::TABLE
            .' (external_id, phone_number, verification_standing, account_state, created_at, updated_at)'
            .' VALUES (?, ?, ?, ?, ?, ?)',
            [
                self::RAISER, '+910000009000', 'VERIFIED', 'ACTIVE',
                '2026-08-22 09:00:00.000000', '2026-08-22 09:00:00.000000',
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

        $migration->delete('DELETE FROM '.DatabaseSafetyIncidentRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseSessionRepository::TABLE);
        $migration->delete('DELETE FROM '.DatabaseUserRepository::TABLE);

        $this->clearEvidentialLog();
    }
}
