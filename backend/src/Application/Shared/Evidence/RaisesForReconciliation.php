<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Evidence;

/**
 * Makes an unrecordable effect visible to operations.
 *
 * `FRD-FR-249`. `BE-060` states the wider principle: the platform must be able
 * to reconcile projections and evidential records from authoritative state where
 * an event dispatch is lost.
 *
 * **The durable register this ought to write to is not specified.** CMP-DOC-11
 * §Appendix names exactly one table in the `ev_` domain and four in `mch_`
 * (`jobs`, `failed_jobs`, `idempotency_registry`, `provider_calls`), and none of
 * them is a reconciliation queue. Adding one would be creating a table the
 * database design does not have. The concern is therefore raised where
 * operations can see it, and the durable register — and what reconciling it
 * actually involves — is reported as unspecified rather than invented.
 */
interface RaisesForReconciliation
{
    public function raise(ReconciliationConcern $concern): void;
}
