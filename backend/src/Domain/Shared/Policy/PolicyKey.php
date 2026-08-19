<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Policy;

use InvalidArgumentException;

/**
 * A value a business owner may change without a code change (`BE-165`).
 *
 * A key carries its declared type, so that `BE-166` — typed and validated, not
 * free-form — holds at the point a value is written (`BE-174`) as well as where
 * it is read.
 *
 * **A key exists only because something reads it.** `DB-153` ‡ makes absence the
 * mechanism by which a policy value cannot relax an absolute rule, so declaring
 * a key ahead of the code that reads it would create exactly the accessor
 * `BADR-12` says must not exist.
 */
final class PolicyKey
{
    private function __construct(
        private readonly string $name,
        private readonly PolicyType $type,
        private readonly string $purpose,
    ) {}

    /**
     * @param  string  $purpose  what reads this value and what it decides, so a
     *                           reviewer can tell whether `BE-172` ‡ is at risk
     */
    public static function of(string $name, PolicyType $type, string $purpose): self
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a policy key. A key is lower-case, dot-separated and names its area, '
                .'so that the register reads as a list of decisions rather than of identifiers.',
                $name,
            ));
        }

        if (trim($purpose) === '') {
            throw new InvalidArgumentException(sprintf(
                'BE-172 ‡: "%s" must record what reads it and what it decides. A policy value whose '
                .'purpose nobody stated cannot be reviewed for whether it relaxes an absolute rule.',
                $name,
            ));
        }

        return new self($name, $type, $purpose);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): PolicyType
    {
        return $this->type;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    /**
     * Whether a raw value satisfies the declared type (`BE-174`).
     *
     * The check runs before a change takes effect, so that a mistyped value is
     * rejected at the point of change rather than becoming a runtime failure in
     * a payment path — which is the failure `BADR-12` names when it rejects an
     * untyped store.
     */
    public function accepts(string $rawValue): bool
    {
        return match ($this->type) {
            PolicyType::Integer => preg_match('/^-?\d+$/', $rawValue) === 1,
            // Exact decimal, matched as text. Never parsed through a float
            // (`DB-033` ‡), because a policy value may be monetary.
            PolicyType::Decimal => preg_match('/^-?\d+(\.\d+)?$/', $rawValue) === 1,
            // A span in whole seconds. Negative is not a span.
            PolicyType::Duration => preg_match('/^\d+$/', $rawValue) === 1,
            PolicyType::Boolean => in_array($rawValue, ['true', 'false'], true),
            PolicyType::Text => $rawValue !== '',
        };
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }
}
