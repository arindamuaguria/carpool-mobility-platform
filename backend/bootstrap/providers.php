<?php

declare(strict_types=1);

use Cmp\Infrastructure\Laravel\Providers\AppServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\EventServiceProvider;
use Cmp\Infrastructure\Laravel\Providers\PersistenceServiceProvider;

return [
    AppServiceProvider::class,
    PersistenceServiceProvider::class,
    EventServiceProvider::class,
];
