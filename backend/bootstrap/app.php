<?php

declare(strict_types=1);

use Cmp\Infrastructure\Laravel\ScheduledWork;
use Cmp\Interface\Console\ListJobFamiliesCommand;
use Cmp\Interface\Console\ProvisionDatabaseAccountsCommand;
use Cmp\Interface\Console\RoutePendingSafetyIncidentsCommand;
use Cmp\Interface\Console\VerifyEvidentialChainCommand;
use Cmp\Interface\Console\VerifySchemaCommand;
use Cmp\Interface\Safety\SafetySurface;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // CMP-DOC-10 §5: the REST surface, versioned in the first path segment
        // (API-019 ‡). apiPrefix is empty because routes/api.php declares the
        // whole path itself — API-019 ‡ fixes the prefix and a second one applied
        // by the framework would put the version in the second segment.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // CMP-DOC-10 §12 / API-163 ‡: the safety surface answers under a prefix
        // of its own, registered from its own file. BE-191 ‡ makes it bootable
        // as a separate entry point sharing the same code, and API-170 ‡
        // requires a deployment implementing only these operations to be able to
        // serve them — which is this file and not routes/api.php.
        //
        // AADR-11 is the operational reason for the separation: a gateway rule
        // or a rate limiter written for the general case must not reach safety
        // traffic (BE-196 ‡, API-168 ‡, OPS-046).
        then: function (): void {
            Route::middleware('api')->group(__DIR__.'/../routes/safety.php');
        },
    )
    ->withCommands([
        ListJobFamiliesCommand::class,
        ProvisionDatabaseAccountsCommand::class,
        RoutePendingSafetyIncidentsCommand::class,
        VerifyEvidentialChainCommand::class,
        VerifySchemaCommand::class,
    ])
    // OPS-039 ‡ / BE-148: scheduled work is declared in exactly one place.
    ->withSchedule(ScheduledWork::declare(...))
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            // API-174: the safety surface carries the same four-branch error
            // model, so it renders the same way. Without this a fault on
            // /safety/v1 would answer a JSON client with HTML.
            fn (Request $request) => $request->is('api/*')
                || $request->is(SafetySurface::PREFIX.'/*')
                || $request->expectsJson(),
        );
    })->create();
