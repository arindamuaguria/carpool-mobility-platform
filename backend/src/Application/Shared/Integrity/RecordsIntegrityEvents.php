<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Integrity;

use Cmp\Application\Shared\Evidence\EvidenceNotRecorded;
use Cmp\Application\Shared\Idempotency\ActorReference;

/**
 * Records an attempt to assert a value only the platform decides.
 *
 * `API-039` ‡: *"A request containing a field whose name matches a known
 * authoritative value shall **additionally** be recorded as an integrity
 * event."* Additionally — the request is already refused by `API-038` ‡; this is
 * the second thing that happens, not the first.
 *
 * `FRD-FR-241` and `NFR-069` are why. A single client sending `fare` is probably
 * a mistake; the same client sending it a hundred times is something else, and
 * `API-204` ‡ makes repetition *"treatable as abuse"*. Neither judgement can be
 * made about events nobody recorded.
 *
 * `SADR-08` places this among security events, and `BE-202` keeps it out of the
 * operational log as a substitute: what `API-039` ‡ asks for is an evidential
 * record, because it is evidence of what a caller attempted.
 */
interface RecordsIntegrityEvents
{
    /**
     * @param  list<string>  $authoritativeValues  the canonical values asserted,
     *                                             from {@see AuthoritativeValues::assertedIn()}
     *
     * @throws EvidenceNotRecorded where the record cannot be written
     */
    public function record(ActorReference $actor, string $operation, array $authoritativeValues): void;
}
