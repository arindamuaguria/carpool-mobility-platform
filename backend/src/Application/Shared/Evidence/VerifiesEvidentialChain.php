<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Evidence;

/**
 * Re-derives the evidential chain and reports the first divergence.
 *
 * `BE-115` / `DB-115` / `SEC-111`. `SEC-113` runs it as scheduled
 * reconciliation; `BE-146` places that in the reconciliation job family.
 *
 * **`SEC-114` and `SEC-115` ‡ are not implemented, and cannot be here.**
 * `SEC-114` requires verification to be incremental, anchored on periodic
 * checkpoints, so its cost does not grow without bound; `SEC-115` ‡ requires a
 * checkpoint to be keyed and independently verifiable. Both need somewhere to
 * keep a checkpoint, and CMP-DOC-11 §Appendix specifies exactly **one** table in
 * the `ev_` domain — `evidential_records`. Creating a checkpoint table would be
 * adding a table the database design does not have.
 *
 * A full pass is what this does. `DB-201` names `ev_evidential_records` as one
 * of the three monotonically growing tables, so the cost `SEC-114` worries about
 * is real and is reported rather than worked around.
 */
interface VerifiesEvidentialChain
{
    /**
     * @param  int|null  $fromRecordId  verify from this record onward, where a
     *                                  caller has an independently trusted
     *                                  starting point. Null verifies from the
     *                                  beginning, which is the only pass that
     *                                  needs no such trust.
     */
    public function verify(?int $fromRecordId = null): ChainVerification;
}
