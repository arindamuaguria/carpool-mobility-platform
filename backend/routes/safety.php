<?php

declare(strict_types=1);

use Cmp\Interface\Rest\Middleware\RefuseAssertedAuthority;
use Cmp\Interface\Rest\Middleware\RequireIdempotencyKey;
use Cmp\Interface\Rest\Middleware\RequireSession;
use Cmp\Interface\Rest\Middleware\RequireSupportedVersion;
use Cmp\Interface\Rest\Middleware\ServeJsonOnly;
use Cmp\Interface\Safety\Controller\IncidentController;
use Cmp\Interface\Safety\SafetySurface;
use Illuminate\Support\Facades\Route;

/*
 * The safety surface (CMP-DOC-10 §12) — a separate file, on purpose.
 *
 * `BE-191` ‡ makes it bootable as a separate entry point sharing the same code,
 * and `API-170` ‡ requires it *"specifiable and servable by a deployment
 * implementing only these operations"*. A deployment that serves only safety
 * registers this file and not `routes/api.php`; `BE-198` leaves whether anyone
 * does that to CMP-DOC-19, and the separation is not conditional on the answer.
 *
 * `API-163` ‡ puts it under a prefix of its own, and `AADR-11` says why in
 * operational terms: a gateway rule or a rate limiter written for the general
 * case will otherwise reach it eventually. `BE-196` ‡ / `API-168` ‡ / `OPS-046`
 * forbid a safety operation being rate-limited to refusal, and a distinct prefix
 * is what lets a gateway honour that without reading the application's mind.
 *
 * **Nothing is throttled here and nothing may be.** `SafetySurfaceRulesTest`
 * fails the build if any throttle, rate-limit or quota middleware is ever
 * attached to this group.
 *
 * ## The same contract, and the same middleware
 *
 * `BADR-16`: the safety surface *"uses the same application services, the same
 * domain and the same store. It contains no logic of its own and duplicates
 * nothing."* So the stack below is the general surface's, not a copy of it —
 * `API-066` ‡ applies idempotency *"exactly as to the general surface"* and
 * `API-174` gives it the same four-branch error model.
 *
 * What is **not** here is `ConfigurationController`'s dependency: nothing on this
 * path reads configuration, because CMP-DOC-10 §12.3 omits it and `API-194` ‡
 * requires that a failure to fetch it never prevent raising an incident.
 */

Route::prefix(SafetySurface::PREFIX.'/{version}')
    ->middleware([
        // API-024 ‡: an unserved version is refused before anything else looks
        // at the request. The same numbers as the general surface, because §12
        // says the safety surface answers on the same contract.
        RequireSupportedVersion::class,
        // API-011: JSON, and nothing negotiated.
        ServeJsonOnly::class,
        // API-066 ‡ / API-173: "a repeated raise under a poor connection
        // produces one incident" — which is the case a client on a bad
        // connection in an emergency is actually in.
        RequireIdempotencyKey::class,
        // FRD-FR-238 ‡: a caller may not assert what the platform decides, on
        // every request, this surface included.
        RefuseAssertedAuthority::class,
    ])
    ->group(function (): void {
        // API-095 ‡ / §9.1: raising an incident is not among the five operations
        // reachable without a session, so it requires one. UC-051's precondition
        // agrees — "A user and, where applicable, a trip are identifiable" — and
        // SEC-044 ‡ is what makes the raiser known without asking.
        Route::middleware(RequireSession::class)->group(function (): void {
            // FRD-FR-185 ‡ — record every safety signal received.
            Route::post('incidents', [IncidentController::class, 'store'])
                ->name('safety.incidents.store');

            // §12.1 — read own incident. There is deliberately no collection:
            // §12.3 omits paging and filtering, and DB-023 ‡ went to some
            // trouble to ensure incidents cannot be enumerated.
            Route::get('incidents/{id}', [IncidentController::class, 'show'])
                ->name('safety.incidents.show');
        });
    });
