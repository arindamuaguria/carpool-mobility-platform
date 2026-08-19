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
 * The record it asks for is an **evidential** one, and `BE-202` is explicit that
 * operational logging *"shall not substitute for"* the evidential log. The
 * evidential writer arrived with `CMP-IMP-439`, so the bound implementation
 * writes one and `SEC-057` ‡ is discharged. Which implementation that is belongs
 * to the composition root and not here — `BE-002` keeps this layer from naming
 * an Infrastructure class, in a docblock as much as in an import.
 *
 * {@see Authoriser::authorise()} calls this **before** it raises, so an
 * implementation that fails to record must raise rather than return: a refusal
 * reported without its record would be exactly the unevidenced outcome
 * `FRD-FR-248` ‡ exists to prevent.
 */
interface RecordsAuthorisationRefusals
{
    public function record(Operation $operation, Actor $actor, AuthorisationRefusalCause $cause): void;
}
