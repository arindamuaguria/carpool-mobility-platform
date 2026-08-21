<?php

declare(strict_types=1);

/**
 * Prints the verification report — CMP-DOC-18 §15.2.
 *
 * `TC-195` ‡ makes the report a release artefact, and a release artefact nobody
 * can run is a specification of one. `composer report` prints it.
 *
 * It lives under `tests/` with the registers it reads: `composer.json` keeps that
 * tree out of the production classmap, and a console command in `src/` able to
 * read the suite's registers would be a production surface reaching into it.
 */

use Tests\VerificationReport;

require __DIR__.'/../vendor/autoload.php';

echo VerificationReport::render();
