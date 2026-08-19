<?php

declare(strict_types=1);

use Cmp\Infrastructure\Laravel\Providers\AppServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\AuthorisationServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\EventServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\PersistenceServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\PortServiceProvider;

return [
    AppServiceProvider::class,
    PersistenceServiceProvider::class,
    EventServiceProvider::class,
    PortServiceProvider::class,
    PolicyServiceProvider::class,
    AuthorisationServiceProvider::class,
];
