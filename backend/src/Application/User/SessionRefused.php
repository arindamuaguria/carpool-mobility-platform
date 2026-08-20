<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Domain\Shared\Refusal\BusinessRefusal;

/**
 * A session would not serve.
 *
 * A {@see BusinessRefusal}, not a fault: the platform decided, and `BE-186` ‡
 * keeps a decision distinct from a fault. `API-107` gives it the same shape as a
 * refusal for absence, which is `SEC-048` ‡'s indistinguishability holding all
 * the way out to the wire.
 *
 * It carries {@see SessionRefusalCause} for the record and
 * {@see SessionRefusal} for the caller, and the two are different on purpose —
 * `SEC-048` ‡ is only satisfied if the caller-facing half stays one value however
 * many internal causes there are.
 */
final class SessionRefused extends BusinessRefusal
{
    private function __construct(private readonly SessionRefusalCause $cause)
    {
        parent::__construct(SessionRefusal::NotUsable);
    }

    public static function because(SessionRefusalCause $cause): self
    {
        return new self($cause);
    }

    /**
     * For the evidential record and the operational log — never for a response.
     */
    public function cause(): SessionRefusalCause
    {
        return $this->cause;
    }
}
