<?php

declare(strict_types=1);

namespace Cmp\Infrastructure\Authorisation;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationRefusalCause;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Authorisation\RecordsAuthorisationRefusals;
use Psr\Log\LoggerInterface;

/**
 * Writes a refused authorisation to the operational log.
 *
 * **This is not the record `SEC-057` ‡ asks for, and does not claim to be.**
 * `BE-202` is explicit: *"Operational logging shall be distinct from the
 * evidential log and shall not substitute for it."* The evidential record
 * arrives with the evidential writer, `CMP-IMP-439`; `ev_evidential_records`
 * does not exist yet.
 *
 * What this does buy: the call path is real and tested, so when the writer
 * exists it is a binding change rather than a hunt for every place a refusal
 * happens. And in the meantime a refusal is at least visible to whoever is
 * watching — `TB-2`, an operator who is authenticated and elevated, is defended
 * by someone noticing.
 *
 * `BE-200`: structured, so it can be searched rather than read.
 * `BE-201` ‡: **no payment credential, no precise location, no contact detail.**
 * The entry carries the operation, the actor reference and the cause, and
 * nothing else — an actor reference is an opaque identity (`DB-024` ‡), not a
 * name or a number.
 */
final class LoggingAuthorisationRefusals implements RecordsAuthorisationRefusals
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function record(Operation $operation, Actor $actor, AuthorisationRefusalCause $cause): void
    {
        $this->logger->warning('authorisation.refused', [
            'operation' => $operation->name(),
            // DB-024 ‡: an opaque reference, never an internal key.
            'actor' => $actor->reference()->toString(),
            'cause' => $cause->name,
            'detail' => $cause->describe(),
            // BE-202: says plainly what this record is not.
            'evidential' => false,
        ]);
    }
}
