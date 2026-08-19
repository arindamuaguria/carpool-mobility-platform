<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Evidence;

/**
 * The **only** way anything reaches the evidential log.
 *
 * `BE-105` ‡ / `BADR-09` / `DB-004` ‡ / `DB-113` ‡: one component writes
 * evidential records and no other component writes them. `BE-117` requires the
 * prohibition to be verified by static analysis, and `TC-037` rule 6 makes it
 * **non-suppressible** — `TC-038` ‡ lists it among the eight that cannot be
 * waived.
 *
 * `BE-106` ‡ / `DB-112` ‡: **the record is written in the same transaction as
 * the operation it evidences.** So this is called from inside an application
 * service's transaction, and never from an after-commit listener — `BE-058` puts
 * listeners outside the producing transaction, which is exactly where an
 * evidential record must not be written from.
 *
 * `FRD-FR-248` ‡: an action is not reported complete where its record cannot be
 * written. Because the write shares the transaction, a failure here rolls the
 * operation back and there is nothing to report. Where the operation had an
 * effect the transaction cannot undo — a provider call made before it opened
 * (`BE-052`) — `FRD-FR-249` applies and the concern is raised for
 * reconciliation.
 */
interface RecordsEvidence
{
    /**
     * @throws EvidenceNotRecorded when the record cannot be written
     */
    public function record(Evidence $evidence): void;
}
