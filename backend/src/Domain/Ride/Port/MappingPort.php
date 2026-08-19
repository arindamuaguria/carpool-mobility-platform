<?php

declare(strict_types=1);

namespace Cmp\Domain\Ride\Port;

use Cmp\Domain\Shared\Port\Port;

/**
 * Places, routes and distances.
 *
 * **No operation is declared yet.** What the platform asks of a mapping
 * capability follows from route matching, and `ARCH-OQ-001` — the route-overlap
 * algorithm and its minimum threshold — is open. `T6` was expressly excluded
 * from the technical decisions ratified on 2026-08-19 and remains a product
 * decision.
 *
 * `BE-035` attaches here: the overlap computation is expressed **independently
 * of any corridor, city or region**, and `DB-020` ‡ says the same of every
 * identifier. A port shaped around one market would make that impossible.
 *
 * `BE-161` records this provider as directed rather than undecided; CMP-DOC-01 §2
 * is where it is named. `BE-150` forbids naming it here.
 */
interface MappingPort extends Port {}
