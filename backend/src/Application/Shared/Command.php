<?php

declare(strict_types=1);

namespace Cmp\Application\Shared;

/**
 * A request to an application service, expressed in application terms.
 *
 * `BE-042`: a command is **not** a transport representation. It carries no HTTP
 * request, no route parameters, no serialised body and no framework type — the
 * interface layer builds one from whatever it received (`BE-005`), so that the
 * same service is invocable from the REST, administrative, safety and worker
 * callers alike (`BE-043`, `BE-013`).
 *
 * A command that changes state carries an idempotency key; see
 * {@see StateChangingCommand}.
 */
interface Command {}
