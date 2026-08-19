<?php

declare(strict_types=1);

use Cmp\Interface\Rest\Controller\VersionsController;
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
 * CMP-DOC-10 §9.1 and nowhere else. Every operation below is one of the five it
 * names; no session middleware exists yet because no operation needing one does.
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
    ->middleware([RequireSupportedVersion::class, ServeJsonOnly::class])
    ->group(function (): void {
        // API-027 / §9.1 — reachable without a session.
        Route::get('versions', VersionsController::class)->name('versions.show');
    });
