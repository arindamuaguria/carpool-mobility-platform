<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Degradation;

use InvalidArgumentException;

/**
 * One thing a capability needs in order to work.
 *
 * `FRD-FR-255` speaks of *"a supporting service"*, and `UC-081`'s supporting
 * actors are the four external ones — `ACT-11` the UPI ecosystem, `ACT-12`
 * mapping and routing, `ACT-13` messaging, `ACT-14` verification. Those are
 * {@see Kind::Service}.
 *
 * ## The second kind, and why it is here
 *
 * A capability can also be unable to work because a value it must read **has not
 * been configured**. `SRS-REQ-158` requires an attempt to use one to be
 * **rejected**, and `SRS-REQ-113` forbids synthesising a result — so the platform
 * refuses, correctly, and a client discovers it by making a request that fails.
 *
 * `FRD-FR-256` ‡ does not permit that: *"the system shall withdraw or mark an
 * affected capability rather than present it as working."* It says **capability**,
 * not *capability affected by a supporting service*, and a health indication
 * reporting a capability as available while every use of it raises `PolicyNotSet`
 * is presenting it as working. `NFR-034` ‡'s *"defined degraded mode"* is the same
 * point from the quality side.
 *
 * So a dependency is of one of two kinds, and both make a capability unable to
 * work. **This is an implementation reading, recorded rather than assumed** —
 * `CC-036` states it, the alternative that was rejected, and the statements above.
 *
 * There is no third kind. The platform's own store is not a dependency in this
 * sense: without it nothing answers at all, including this, and a health
 * indication that could report *"the database is gone"* would have had to reach
 * the database to say so.
 */
final class Support
{
    private function __construct(
        private readonly Kind $kind,
        private readonly string $name,
    ) {}

    /**
     * A supporting service (`FRD-FR-255`, `UC-081`'s `ACT-11`–`ACT-14`).
     *
     * Named in the platform's terms. `API-090` and `BE-150` both forbid the
     * supplier appearing, and this name reaches a client through `API-091`.
     */
    public static function service(string $name): self
    {
        return new self(Kind::Service, self::assertName($name));
    }

    /**
     * A policy value that must be set before the capability can work
     * (`SRS-REQ-158`).
     *
     * The name is the policy key, which is already the platform's own term and is
     * already declared in exactly one place (`DB-153` ‡).
     */
    public static function policyValue(string $key): self
    {
        return new self(Kind::PolicyValue, self::assertName($key));
    }

    public function kind(): Kind
    {
        return $this->kind;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->name === $other->name;
    }

    private static function assertName(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not the name of a supporting dependency. FRD-FR-257 tells the actor what is '
                .'unavailable, so it is stated in the platform\'s terms and never as a provider or a class.',
                $name,
            ));
        }

        return $name;
    }
}
