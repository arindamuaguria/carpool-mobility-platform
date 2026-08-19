<?php

declare(strict_types=1);

use Cmp\Infrastructure\Laravel\ScheduledWork;
use Cmp\Interface\Console\ListJobFamiliesCommand;
use Cmp\Interface\Console\ProvisionDatabaseAccountsCommand;
use Cmp\Interface\Console\VerifyEvidentialChainCommand;
use Cmp\Interface\Console\VerifySchemaCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ListJobFamiliesCommand::class,
        ProvisionDatabaseAccountsCommand::class,
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
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
