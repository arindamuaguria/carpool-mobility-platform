<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

use InvalidArgumentException;

/**
 * A named role, and the capabilities it grants.
 *
 * `SEC-063`: *"Role definitions and their capabilities are
 * `[TBD – Business Decision Required]`; the mechanism is specified and the role
 * set is not."* `BAD-DEC-006` is open, and `ADM-168` records that the
 * administrative unit cannot start without it. This class is the mechanism; the
 * set lives in the role register, which is empty.
 *
 * `SEC-062` ‡: **no role exists that is exempt from an absolute business rule.**
 * A role holds capabilities and nothing else — there is no field here in which
 * an exemption could be expressed, so the rule holds by construction rather than
 * by review.
 *
 * `FRD-FR-254`: user roles are distinguished from administrative roles and
 * restricted independently. The distinction is carried here so that a rule can
 * require one kind without accidentally admitting the other.
 */
final class Role
{
    /** @var list<Capability> */
    private readonly array $capabilities;

    /**
     * @param  list<Capability>  $capabilities
     */
    private function __construct(
        private readonly string $name,
        private readonly RoleKind $kind,
        array $capabilities,
    ) {
        $this->capabilities = $capabilities;
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    public static function user(string $name, array $capabilities): self
    {
        return self::of($name, RoleKind::User, $capabilities);
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    public static function administrative(string $name, array $capabilities): self
    {
        return self::of($name, RoleKind::Administrative, $capabilities);
    }

    /**
     * @param  list<Capability>  $capabilities
     */
    private static function of(string $name, RoleKind $kind, array $capabilities): self
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a role name.', $name));
        }

        return new self($name, $kind, array_values($capabilities));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function kind(): RoleKind
    {
        return $this->kind;
    }

    /**
     * @return list<Capability>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function grants(Capability $capability): bool
    {
        foreach ($this->capabilities as $held) {
            if ($held->equals($capability)) {
                return true;
            }
        }

        return false;
    }
}
