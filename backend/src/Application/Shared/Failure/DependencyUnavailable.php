<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Failure;

use InvalidArgumentException;

/**
 * An external capability did not answer. **Nothing was decided.**
 *
 * `API-089` ‡: the branch conveys that nothing was decided — neither success nor
 * failure. `API-076` ‡: it is never represented as success. `BE-187` ‡ /
 * `API-075` ‡: it is never represented as a business refusal, which would assert
 * a decision that was not taken. `FRD-FR-258` ‡: an unknown outcome is not
 * resolved by assumption in either direction.
 *
 * `API-090`: the branch does not name the provider or expose its error. This
 * class therefore holds **no provider identity and no provider error** — it
 * carries the affected *capability*, which is what `FRD-FR-257` requires the
 * actor to be told and what `API-091` propagates so the client can disclose what
 * remains available.
 */
final class DependencyUnavailable extends Failure
{
    private function __construct(
        private readonly string $capability,
        private readonly bool $retryMayHelp,
    ) {
        if (trim($capability) === '') {
            throw new InvalidArgumentException(
                'FRD-FR-257: the affected capability must be named so the actor can be told what is unavailable.'
            );
        }
    }

    /**
     * @param  string  $capability  The platform capability that could not be
     *                              exercised — never the provider behind it
     *                              (`API-090`).
     */
    public static function ofCapability(string $capability, bool $retryMayHelp = true): self
    {
        return new self($capability, $retryMayHelp);
    }

    public function branch(): FailureBranch
    {
        return FailureBranch::DependencyUnavailable;
    }

    /** The affected capability. Never a provider name (`API-090`). */
    public function capability(): string
    {
        return $this->capability;
    }

    /** Optional retry guidance (CMP-DOC-10 §8.1). */
    public function retryMayHelp(): bool
    {
        return $this->retryMayHelp;
    }

    /**
     * Reads as documentation at every call site: this branch decides nothing.
     *
     * `API-089` ‡, `BE-152`.
     */
    public function nothingWasDecided(): bool
    {
        return true;
    }
}
