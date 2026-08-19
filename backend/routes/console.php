<?php

declare(strict_types=1);

/*
 * Console entry points.
 *
 * The platform's commands are registered in bootstrap/app.php as classes under
 * `Cmp\Interface\Console`, where `BE-005` puts an adapter. Nothing is defined
 * here as a closure: a closure command has no class to hold its contract and no
 * place for a test to reach it.
 *
 * Scheduled work is declared in `Cmp\Infrastructure\Laravel\ScheduledWork` and
 * nowhere else — `OPS-039` ‡ requires exactly one active scheduler, and one
 * declaration point is what makes what it runs reviewable.
 */
