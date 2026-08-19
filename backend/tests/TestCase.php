<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base case for tests that need the framework booted — levels 3, 4 and 5.
 *
 * Domain tests (level 2) must not extend this. `TC-029` requires a domain test
 * to run without a database, a framework or a network; those extend
 * PHPUnit\Framework\TestCase directly.
 */
abstract class TestCase extends BaseTestCase
{
    //
}
