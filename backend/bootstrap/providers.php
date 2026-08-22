<?php

declare(strict_types=1);

use Cmp\Infrastructure\Laravel\Providers\AppServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\AuthorisationServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\ConfigurationServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\DegradationServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\EventServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\EvidentialServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\PersistenceServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\PolicyServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\PortServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\SafetyServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\UserServiceProvider;

return [
    AppServiceProvider::class,
    PersistenceServiceProvider::class,
    EventServiceProvider::class,
    PortServiceProvider::class,
    PolicyServiceProvider::class,
    AuthorisationServiceProvider::class,
    EvidentialServiceProvider::class,
    UserServiceProvider::class,
    // BE-192 ‡: what the safety capability depends on, in one readable place.
    SafetyServiceProvider::class,
    // Last: its register declares capabilities against keys the providers above own.
    ConfigurationServiceProvider::class,
    DegradationServiceProvider::class,
];
