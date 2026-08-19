<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Laravel\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Framework composition root.
 *
 * This provider belongs to Infrastructure (`BE-008`). It exists to bind Domain
 * port interfaces and Domain repository interfaces to their Infrastructure
 * implementations (`BE-036`, `BE-037`). It carries no business rule (`BE-011`).
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bindings are registered here as ports and repositories are implemented.
    }

    public function boot(): void
    {
        //
    }
}
