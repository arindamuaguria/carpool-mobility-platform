<?php

declare(strict_types=1);

namespace Cmp\Domain\Shared\Port;

use InvalidArgumentException;
use LogicException;

/**
 * What every port returns (`BE-151`).
 *
 * `BADR-11` rejected the two obvious alternatives explicitly: a port returning a
 * plain value, or throwing on failure, **erases the distinction between reported
 * and verified** — and that distinction is the whole of `TB-3`.
 *
 * The separation is enforced by the accessors, not by convention. A caller that
 * wants a fact calls {@see verifiedValue()}, which raises on anything but
 * `Verified`; a caller reading what a provider claimed calls
 * {@see reportedValue()}. There is no accessor that returns "the value" without
 * the caller saying which of the two it believes it is holding.
 *
 * `BE-152` ‡: `Unavailable` carries no value at all, so it cannot be read as
 * either. `SRS-REQ-113` forbids synthesising a result, and a type that could
 * yield a value for an answer that never arrived would be an invitation to.
 */
final class CapabilityResult
{
    private function __construct(
        private readonly CapabilityOutcome $outcome,
        private readonly mixed $value,
        private readonly ?string $reasonIdentifier,
        private readonly ?string $capability,
    ) {}

    /**
     * The platform has confirmation it may treat as fact (`BE-158` ‡).
     */
    public static function verified(mixed $value): self
    {
        return new self(CapabilityOutcome::Verified, $value, null, null);
    }

    /**
     * The provider said this. It is not a fact.
     */
    public static function reported(mixed $value): self
    {
        return new self(CapabilityOutcome::Reported, $value, null, null);
    }

    /**
     * The capability did not answer. Nothing was decided (`BE-152` ‡).
     *
     * @param  string  $capability  what is unavailable, in the platform's terms —
     *                              never the provider behind it (`API-090`)
     */
    public static function unavailable(string $capability): self
    {
        if (trim($capability) === '') {
            throw new InvalidArgumentException(
                'FRD-FR-257: the affected capability must be named so the actor can be told what is unavailable.'
            );
        }

        return new self(CapabilityOutcome::Unavailable, null, null, $capability);
    }

    /**
     * The provider declined, for the stated reason.
     *
     * `BE-154`: an adapter translates a provider error into a port result
     * **without leaking the provider representation**. The identifier is the
     * platform's, not the provider's error code.
     */
    public static function rejected(string $reasonIdentifier): self
    {
        if (trim($reasonIdentifier) === '') {
            throw new InvalidArgumentException('A rejection carries the reason the platform recorded for it.');
        }

        return new self(CapabilityOutcome::Rejected, null, $reasonIdentifier, null);
    }

    public function outcome(): CapabilityOutcome
    {
        return $this->outcome;
    }

    public function isVerified(): bool
    {
        return $this->outcome === CapabilityOutcome::Verified;
    }

    public function isReported(): bool
    {
        return $this->outcome === CapabilityOutcome::Reported;
    }

    public function isUnavailable(): bool
    {
        return $this->outcome === CapabilityOutcome::Unavailable;
    }

    public function isRejected(): bool
    {
        return $this->outcome === CapabilityOutcome::Rejected;
    }

    /**
     * The value, where the platform has confirmation it may treat as fact.
     *
     * `SRS-REQ-106`: *"the provider reported X"* must never become *"X is
     * true"*. Reading a reported value through this accessor raises rather than
     * returns, which is the mistake being prevented.
     */
    public function verifiedValue(): mixed
    {
        if (! $this->isVerified()) {
            throw new LogicException(sprintf(
                'SRS-REQ-106: a %s result is not a verified fact and must not be read as one.',
                $this->outcome->name,
            ));
        }

        return $this->value;
    }

    /**
     * What the provider claimed. Never a fact.
     */
    public function reportedValue(): mixed
    {
        if (! $this->isReported()) {
            throw new LogicException(sprintf(
                'A %s result carries nothing the provider merely reported.',
                $this->outcome->name,
            ));
        }

        return $this->value;
    }

    /**
     * The reason a provider declined, in the platform's terms (`BE-154`).
     */
    public function rejectionReason(): string
    {
        if (! $this->isRejected()) {
            throw new LogicException('Only a rejected result carries a rejection reason.');
        }

        /** @var string */
        return $this->reasonIdentifier;
    }

    /**
     * What is unavailable, so the actor can be told (`FRD-FR-257`, `API-091`).
     */
    public function unavailableCapability(): string
    {
        if (! $this->isUnavailable()) {
            throw new LogicException('Only an unavailable result names an unavailable capability.');
        }

        /** @var string */
        return $this->capability;
    }
}
