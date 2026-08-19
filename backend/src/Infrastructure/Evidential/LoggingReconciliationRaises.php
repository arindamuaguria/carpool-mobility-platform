<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Evidential;

use Cmp\Application\Shared\Evidence\RaisesForReconciliation;
use Cmp\Application\Shared\Evidence\ReconciliationConcern;
use Psr\Log\LoggerInterface;

/**
 * Makes an unrecordable effect visible to operations.
 *
 * `FRD-FR-249`. **The durable register this ought to write to is not
 * specified**, and that is reported rather than invented: CMP-DOC-11 §Appendix
 * names one table in `ev_` and four in `mch_`, and none is a reconciliation
 * queue. `BE-202` is separately clear that operational logging *"shall not
 * substitute for"* the evidential log — and this is not an attempt to, because
 * the whole point of the concern is that the evidential record could **not** be
 * written.
 *
 * What this buys: the concern is raised, at a severity that surfaces it, with
 * enough for an operator to reconcile against the provider. What it does not
 * buy: durability across a log rotation, or a queue anyone works through.
 *
 * `BE-201` ‡: no payment credential, no precise location, no contact detail. The
 * entry names the action, the subject as an external identifier, and what the
 * platform believes took effect.
 */
final class LoggingReconciliationRaises implements RaisesForReconciliation
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function raise(ReconciliationConcern $concern): void
    {
        $this->logger->critical('evidence.reconciliation_required', [
            'action' => $concern->action(),
            'subject' => $concern->subject(),
            'took_effect' => $concern->whatTookEffect(),
            'raised_at' => $concern->raisedAt()->toIso8601(),
            // BE-202: says plainly what this record is not.
            'evidential' => false,
        ]);
    }
}
