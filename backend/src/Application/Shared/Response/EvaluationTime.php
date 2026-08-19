<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\Response;

use Cmp\Domain\Shared\Time\Clock;

/**
 * The instant the platform evaluated a response.
 *
 * `API-043` ‡: *"Every response shall carry the time at which the platform
 * evaluated it."* `AADR-09` gives the reason — a client that cannot tell when a
 * value was true cannot tell whether it still is, and `FRD-FR-150` forbids
 * presenting a cached value as authoritative.
 *
 * **This exists so the adapter does not have to read a clock.** `BE-036` declares
 * the clock as a port in the Domain, and `BE-002` keeps Domain types out of the
 * `Interface` layer; a controller calling `Clock::now()` would reach across two
 * layers to stamp a header. It hands back a string because that is what the
 * envelope needs and `API-015` fixes the form: a single unambiguous
 * representation carrying an offset.
 *
 * `API-044` ‡ — a value derived from a projection carries **that projection's**
 * maintenance time, not this one — is deliberately not served here. No projection
 * exists (`ARCH-113` puts them with `BE-017`'s aggregates), and the maintenance
 * time belongs to the projection that produced the value rather than to the
 * response as a whole. It arrives with the first projection, alongside it.
 */
final class EvaluationTime
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * `API-015`: ISO 8601 with an offset, so no reader has to guess a zone.
     */
    public function stamp(): string
    {
        return $this->clock->now()->toIso8601();
    }
}
