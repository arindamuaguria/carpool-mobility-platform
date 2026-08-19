<?php

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;

/**
 * Base case for level-2 domain tests.
 *
 * `TC-029` / `TC-058` ‡: a domain test shall run without a database, a
 * framework or a network. This class deliberately does not boot Laravel, and
 * nothing under tests/Domain may reference a framework type.
 */
abstract class DomainTestCase extends TestCase {}
