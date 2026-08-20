<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Authorisation;

use RuntimeException;

/**
 * A role is declared but nothing records who holds it.
 *
 * Raised by {@see RegisteredActorRoles} the moment the role register stops being
 * empty without an assignment to read from. `SEC-045` ‡ requires entitlement to
 * be *"evaluated against platform state"*, and a resolver that answered "no
 * roles" once a role set existed would be answering from nothing — which is the
 * one failure mode `SEC-055` ‡'s deny-by-default does **not** make safe, because
 * it would silently under-permit a user who should hold a capability and look
 * like an authorisation decision.
 *
 * A fault rather than a refusal: `BE-186` ‡ keeps a platform fault distinct from
 * a decision the platform made, and this is the platform being unable to decide.
 */
final class RoleAssignmentNotAvailable extends RuntimeException {}
