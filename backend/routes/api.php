<?php

declare(strict_types=1);

use Cmp\Interface\Rest\Controller\ConfigurationController;
use Cmp\Interface\Rest\Controller\CurrentSessionController;
use Cmp\Interface\Rest\Controller\HealthController;
use Cmp\Interface\Rest\Controller\VersionsController;
use Cmp\Interface\Rest\Middleware\RefuseAssertedAuthority;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\Middleware\RequireSession;
use Cmp\Interface\Rest\Middleware\RequireSupportedVersion;
use Cmp\Interface\Rest\Middleware\ServeJsonOnly;
use Cmp\Interface\Rest\ServedVersions;
use Illuminate\Support\Facades\Route;

/*
 * The REST surface (CMP-DOC-10).
 *
 * `API-019` ‡ puts the version in the first path segment. The group is registered
 * under a `{version}` parameter rather than a literal `v1` so that a request for
 * a version nobody serves reaches `RequireSupportedVersion` and receives the
 * distinct version-unsupported outcome `API-024` ‡ requires — a literal prefix
 * would produce the `404` that statement forbids.
 *
 * `API-110`: which operations are reachable without a session is stated in
 * CMP-DOC-10 §9.1 and nowhere else. `versions` is one of the five it names;
 * everything else sits inside the `RequireSession` group, and
 * `RestRoutingRulesTest` fails the build if an operation is ever added outside
 * it.
 *
 * **The catalogue is nearly empty, and that is the specification's shape rather
 * than this file's.** CMP-DOC-10 §11 lists the operations for behaviour
 * CMP-DOC-04 specified; each needs an application service, and `BE-017`'s nine
 * aggregates are unbuilt. §11.14 lists eleven further resources that would be
 * needed and names the decision blocking each. Nothing is stubbed here for any of
 * them — `ADM-187`/`ADM-191` forbid a placeholder for a withheld item, and an
 * operation with no application service behind it would violate `API-002` ‡.
 */

Route::prefix(ServedVersions::PREFIX.'/{version}')
    ->middleware([
        // API-024 ‡: an unserved version is refused before anything else looks
        // at the request, and API-026 keeps that reachable without a session.
        RequireSupportedVersion::class,
        // API-011: JSON, and nothing negotiated.
        ServeJsonOnly::class,
        // API-057 ‡ / API-058 ‡: every state-changing request carries a key.
        // Applied to the group rather than per route, so an operation cannot be
        // added without it — AADR-04 made the key mandatory, and a per-route
        // opt-in is how "mandatory" becomes "usually".
        RequireIdempotencyKey::class,
        // FRD-FR-238 to FRD-FR-240 ‡: a caller may not assert what the platform
        // decides. On the group rather than per route, because FRD-FR-238 ‡ is
        // about **every** request and a guarantee remembered per route is one
        // that lapses on the route somebody adds in a hurry.
        RefuseAssertedAuthority::class,
    ])
    ->group(function (): void {
        // API-027 / §9.1 — reachable without a session.
        Route::get('versions', VersionsController::class)->name('versions.show');

        // BE-203 / §11.13 — platform health, distinguished from dependencies.
        // §9.1 names it among the five reachable without a session, and that
        // placement is the point: a client that cannot authenticate because the
        // capability that authenticates is withdrawn must still learn why.
        Route::get('health', HealthController::class)->name('health.show');

        // §14.1 / MADR-11 — the public subset, which §9.1 makes reachable
        // without a session because API-193's conservative defaults are what a
        // cold client runs on until it has fetched them.
        Route::get('configuration', ConfigurationController::class)->name('configuration.show');

        // API-095 ‡: everything CMP-DOC-10 §9.1 does not name requires an
        // authenticated session. RequireSession establishes who is calling;
        // SEC-054 ‡ and API-097 ‡ leave whether they may to the application
        // service, which evaluates the rule AuthorisationServiceProvider states.
        //
        // AADR-07 / API-006: `sessions/current` is a resource and `refresh` is
        // its sub-resource, not a verb — the method carries the action.
        Route::middleware(RequireSession::class)->group(function (): void {
            Route::delete('sessions/current', [CurrentSessionController::class, 'destroy'])
                ->name('sessions.current.terminate');

            Route::post('sessions/current/refresh', [CurrentSessionController::class, 'refresh'])
                ->name('sessions.current.refresh');
        });
    });
