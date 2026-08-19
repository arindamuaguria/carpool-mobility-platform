<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Evidence;

use Cmp\Domain\Shared\Time\Instant;

/**
 * Something took effect that the platform could not record.
 *
 * `FRD-FR-249`: *"The system shall raise for reconciliation any action that took
 * effect but whose record could not be written."*
 *
 * The case is narrow and specific. `BE-106` ‡ writes the evidential record in
 * the operation's own transaction, so a failed write normally rolls the
 * operation back and there is nothing that took effect. The exception is an
 * effect the transaction cannot undo — a provider call made **before** it opened,
 * which `BE-050` ‡ and `BE-052` require — where the money has moved and the
 * record has not.
 *
 * `SEC-112` ‡ applies by analogy and is worth stating: a break is a finding, not
 * a fault to correct. Nothing here repairs anything.
 */
final class ReconciliationConcern
{
    private function __construct(
        private readonly string $action,
        private readonly string $subject,
        private readonly string $whatTookEffect,
        private readonly Instant $raisedAt,
    ) {}

    /**
     * @param  string  $whatTookEffect  what the platform believes happened that it
     *                                  could not evidence — stated so an operator
     *                                  can reconcile it against the provider
     */
    public static function evidenceCouldNotBeWritten(
        Evidence $evidence,
        string $whatTookEffect,
        Instant $raisedAt,
    ): self {
        return new self($evidence->action(), $evidence->subject(), $whatTookEffect, $raisedAt);
    }

    public function action(): string
    {
        return $this->action;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function whatTookEffect(): string
    {
        return $this->whatTookEffect;
    }

    public function raisedAt(): Instant
    {
        return $this->raisedAt;
    }
}
