<?php

declare(strict_types=1);

/*
 * Filesystem disks.
 *
 * Only a private local disk is configured. No object-storage supplier has been
 * selected (readiness analysis §25 action 11), so no cloud disk is declared, and
 * BE-015 forbids environment-specific values in source. There is no publicly
 * served asset surface: the platform exposes a REST interface and an
 * administrative interface, not a website (ARCH-016, BE-013).
 */

return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // No unauthenticated file-serving route. Authorisation is evaluated in
            // the application layer on one path (SADR-06, BADR-14); a framework route
            // that streams stored files bypasses it.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

    ],

    'links' => [],

];
