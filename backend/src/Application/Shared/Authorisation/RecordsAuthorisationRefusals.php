<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Authorisation;

/**
 * Records a refused authorisation.
 *
 * `SEC-057` ‡ / `BE-183` / `API-088` ‡ / `ARCH-135` / `NFR-060`: **every**
 * refused authorisation is recorded. A refusal nobody can see is a probe nobody
 * noticed, and `TB-2` — an operator who is authenticated and elevated — is
 * defended by noticing.
 *
 * **`SEC-057` ‡ is not yet discharged.** The record it asks for is an evidential
 * one, and the evidential writer is `CMP-IMP-439`; `ev_evidential_records` does
 * not exist. `BE-202` is explicit that operational logging *"shall not
 * substitute for"* the evidential log, so the interim implementation is not a
 * substitute and does not claim to be. The call path is built and tested, and
 * the sink is what is missing.
 */
interface RecordsAuthorisationRefusals
{
    public function record(Operation $operation, Actor $actor, AuthorisationRefusalCause $cause): void;
}
