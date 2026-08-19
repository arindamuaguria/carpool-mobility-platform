<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Policy;

use LogicException;

/**
 * A policy value as read, together with the version it came from.
 *
 * `BE-167`: policy configuration is versioned, **and a decision shall record the
 * version it used**. A decision that recorded only the value could never be
 * re-examined later against the rules that were in force when it was taken.
 *
 * The accessors enforce the declared type at the point of reading as well as at
 * the point of writing (`BE-166`). Reading a decimal as an integer raises rather
 * than truncating: `BADR-12` rejected an untyped store because a mistyped value
 * becomes a runtime failure somewhere far from the mistake, and a silent
 * truncation would be exactly that.
 */
final class PolicyValue
{
    public function __construct(
        private readonly PolicyKey $key,
        private readonly string $rawValue,
        private readonly int $version,
    ) {}

    public function key(): PolicyKey
    {
        return $this->key;
    }

    /**
     * The version this value came from (`BE-167`).
     */
    public function version(): int
    {
        return $this->version;
    }

    public function asInteger(): int
    {
        $this->requireType(PolicyType::Integer);

        return (int) $this->rawValue;
    }

    /**
     * The value as an **exact decimal string**.
     *
     * `DB-033` ‡ forbids a monetary value in a floating-point type under any
     * circumstance, and a policy value may be monetary. It is never converted to
     * a float here, so there is no point at which precision could be lost.
     */
    public function asDecimal(): string
    {
        $this->requireType(PolicyType::Decimal);

        return $this->rawValue;
    }

    /**
     * A span, in whole seconds.
     */
    public function asDurationInSeconds(): int
    {
        $this->requireType(PolicyType::Duration);

        return (int) $this->rawValue;
    }

    public function asBoolean(): bool
    {
        $this->requireType(PolicyType::Boolean);

        return $this->rawValue === 'true';
    }

    public function asText(): string
    {
        $this->requireType(PolicyType::Text);

        return $this->rawValue;
    }

    private function requireType(PolicyType $expected): void
    {
        if ($this->key->type() !== $expected) {
            throw new LogicException(sprintf(
                'BE-166: "%s" is declared as %s and cannot be read as %s.',
                $this->key->name(),
                $this->key->type()->value,
                $expected->value,
            ));
        }
    }
}
