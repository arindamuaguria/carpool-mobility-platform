<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

use Cmp\Application\Shared\Idempotency\ActorReference;

/**
 * Who is acting, and what they hold — **resolved from platform state**.
 *
 * `SEC-045` ‡: *"A session shall carry no authorisation claim; entitlement shall
 * be evaluated against platform state on every request."* `BE-181` ‡ and
 * `SEC-056` ‡ say the same of ownership and relationship. An `Actor` is
 * therefore built by {@see ResolvesActorRoles} from the platform's own record of
 * what this identity holds, never deserialised from a token.
 *
 * Nothing here can be constructed from a request. The only way to obtain one is
 * to ask the platform, and `AuthorisationRulesTest` fails the build if a session
 * or token type appears in this namespace.
 *
 * `BADR-14`'s stated consequence: *"The acting identity must be threaded into
 * every service call, including from jobs."* That is why an `Actor` is a
 * parameter of every application service operation rather than something read
 * from ambient context — a worker has no ambient context to read.
 */
final class Actor
{
    /** @var list<Role> */
    private readonly array $roles;

    /**
     * @param  list<Role>  $roles
     */
    private function __construct(
        private readonly ActorReference $reference,
        array $roles,
    ) {
        $this->roles = $roles;
    }

    /**
     * @param  list<Role>  $roles  the roles the platform records this identity as
     *                             holding — never roles a caller asserted
     */
    public static function holding(ActorReference $reference, array $roles): self
    {
        return new self($reference, array_values($roles));
    }

    public function reference(): ActorReference
    {
        return $this->reference;
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * `FRD-FR-251` ‡: the roles held are determined **before** any action is
     * permitted. `FRD-FR-252` ‡: an action is permitted only where a role held
     * allows it.
     */
    public function holds(Capability $capability): bool
    {
        foreach ($this->roles as $role) {
            if ($role->grants($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any role of this kind is held (`FRD-FR-254`, `SEC-061`).
     */
    public function holdsRoleOfKind(RoleKind $kind): bool
    {
        foreach ($this->roles as $role) {
            if ($role->kind() === $kind) {
                return true;
            }
        }

        return false;
    }

    public function is(ActorReference $other): bool
    {
        return $this->reference->toString() === $other->toString();
    }
}
