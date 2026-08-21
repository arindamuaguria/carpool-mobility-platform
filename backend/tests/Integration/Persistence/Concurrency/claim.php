<?php

declare(strict_types=1);

/**
 * One racer in `TC-147` ‡'s concurrent idempotent replay.
 *
 * `TADR-08`: *"concurrency under genuine parallelism."* `TC-143` ‡ says the same
 * of seat allocation and states the reason CMP-DOC-18 gives for the whole
 * decision — **not simulated sequence**. A test that opens two connections and
 * then interleaves them itself has chosen the interleaving, and the one it
 * chooses is rarely the one that breaks anything.
 *
 * So each racer is a separate operating-system process, and this file is what one
 * of them runs. `ConcurrentIdempotencyClaimTest` starts several, holds them at a
 * barrier, and lets them go at the same instant.
 *
 * ## The barrier
 *
 * Starting N processes takes long enough that the first would finish before the
 * last began, and the race would never happen. Each is given a start instant as a
 * microsecond timestamp and busy-waits for it, so the contention is real rather
 * than hoped for.
 *
 * ## What it prints, and why that shape
 *
 * One line: `claimed` or `refused`, or `error <message>`. The parent asserts
 * across **all** outcomes rather than on a particular ordering, which is
 * `TC-145` ‡ — and `TC-150` ‡ forbids fixing a flaky concurrency test by retrying
 * it into passing, so the assertion has to hold whichever process wins.
 *
 * ## Not in the production artefact
 *
 * `OPS-099` and `TC-155` ‡ require failure-induction hooks to be absent from the
 * production artefact. This is under `tests/`, which `composer.json` excludes
 * from the autoloader's production classmap; it is a test fixture, not a hook the
 * platform can reach.
 *
 * Arguments: 1 start instant (microseconds), 2 actor, 3 operation, 4 key,
 * 5 content fingerprint.
 */

use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Idempotency\IdempotencyKey;
use Cmp\Application\Shared\Idempotency\IdempotencyRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../../bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

[$startAt, $actor, $operation, $key, $fingerprint] = array_slice($argv, 1);

// The registry is resolved before the barrier, so that the connection is open
// and the container warm — otherwise the racers would be racing to boot rather
// than racing to claim.
$registry = $app->make(IdempotencyRegistry::class);

$start = (float) $startAt;

while (microtime(true) < $start) {
    // Busy-wait rather than usleep: the resolution that matters here is smaller
    // than a scheduler tick, and this loop lasts milliseconds.
}

try {
    $claimed = $registry->claim(
        ActorReference::fromString($actor),
        $operation,
        IdempotencyKey::fromString($key),
        $fingerprint,
    );

    echo $claimed ? 'claimed' : 'refused';
} catch (Throwable $failure) {
    // Printed rather than swallowed. TC-150 ‡: a flaky concurrency test is
    // fixed, and a racer that failed silently would make the count come out
    // right for the wrong reason.
    echo 'error '.$failure::class.': '.$failure->getMessage();
}
