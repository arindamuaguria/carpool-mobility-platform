<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

/**
 * `FRD-FR-254`: the system distinguishes user roles from administrative roles
 * and restricts each independently.
 *
 * The distinction is not cosmetic. `SEC-009` ‡ requires an operator request to
 * traverse the **same** authorisation evaluation as a client request, and
 * `SEC-061` requires administrative capability to be restricted by
 * administrative role. One evaluation, two kinds of role — which is only
 * expressible if the kinds are distinguishable.
 */
enum RoleKind
{
    case User;

    case Administrative;
}
