<?php

declare(strict_types=1);

namespace Cmp\Application\Shared;

use Cmp\Application\Shared\Failure\Failure;
use LogicException;

/**
 * What an application service returns.
 *
 * `BE-046`: a result expressed in application terms, distinguishing success from
 * each failure class. A result is either a success carrying a value, or a
 * failure carrying exactly one of the four branches of `API-071` ‡ — never
 * both, never neither.
 *
 * Returning a result rather than letting an exception escape is what lets the
 * same service serve four callers (`BE-013`, `BE-043`): the interface layer maps
 * the branch to transport (`BADR-17`), the administrative and worker callers map
 * it to their own surfaces, and no caller has to know which exception types the
 * domain raises.
 *
 * @template-covariant TValue
 */
final class Result
{
    /**
     * @param  TValue|null  $value
     */
    private function __construct(
        private readonly mixed $value,
        private readonly ?Failure $failure,
    ) {}

    /**
     * @template TSuccess
     *
     * @param  TSuccess  $value
     * @return self<TSuccess>
     */
    public static function success(mixed $value): self
    {
        return new self($value, null);
    }

    /**
     * @return self<null>
     */
    public static function succeeded(): self
    {
        return new self(null, null);
    }

    /**
     * @return self<mixed>
     */
    public static function failed(Failure $failure): self
    {
        return new self(null, $failure);
    }

    public function isSuccess(): bool
    {
        return $this->failure === null;
    }

    public function isFailure(): bool
    {
        return $this->failure !== null;
    }

    /**
     * @return TValue
     */
    public function value(): mixed
    {
        if ($this->failure !== null) {
            throw new LogicException(
                'A failed result carries no value. Check isSuccess() before reading it.'
            );
        }

        /** @var TValue */
        return $this->value;
    }

    public function failure(): Failure
    {
        if ($this->failure === null) {
            throw new LogicException(
                'A successful result carries no failure. Check isFailure() before reading it.'
            );
        }

        return $this->failure;
    }
}
