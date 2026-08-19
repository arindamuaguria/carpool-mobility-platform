<?php

declare(strict_types=1);

namespace Cmp\Application\Shared;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Domain\Shared\Refusal\BusinessRefusal;

/**
 * The base every application service extends.
 *
 * `BE-041`: each use case is realised by exactly one application service
 * operation — one class, one `handle`.
 *
 * `BE-044` / `BADR-14` / `SEC-053` ‡: **authorisation is evaluated before the
 * domain is invoked.** It happens here, in `execute()`, so it cannot be
 * forgotten in a service that omits to call it. `SADR-06` makes this the single
 * path for every caller — client, operator, worker, safety surface — and
 * rejected the alternative in its own words: *"a queue worker or a Filament
 * resource bypassing the HTTP stack would bypass authorisation entirely."*
 *
 * `BE-042`: the operation accepts a {@see Command} in application terms, not a
 * transport representation. `BE-043`: it is invocable from any caller without
 * HTTP context — nothing here reads a request, a session or a route. The acting
 * identity is a **parameter**, which is `BADR-14`'s stated consequence: *"The
 * acting identity must be threaded into every service call, including from
 * jobs."*
 *
 * `BE-046`: it returns a {@see Result} distinguishing success from each failure
 * class.
 *
 * **Only `BusinessRefusal` is caught.** `BE-186` ‡ forbids an internal fault
 * being represented as a business refusal; a broad catch here would do exactly
 * that, turning a defect into a refusal the caller would be told is final. An
 * authorisation refusal is a `BusinessRefusal`, so it converts through the same
 * path and reaches the caller as `access.not_available_to_you` — which
 * `SEC-069` ‡ requires to be indistinguishable from the record not existing.
 *
 * One obligation attaches here and is not yet implemented: `BE-047` ‡
 * transaction boundaries owned by the application layer (`CMP-IMP-024` provides
 * the boundary; a service opens one when it has state to change).
 */
abstract class ApplicationService
{
    public function __construct(private readonly Authoriser $authoriser) {}

    /**
     * The operation this service performs, as the authorisation policy names it.
     *
     * `SEC-055` ‡: an operation with no stated rule is refused, so declaring this
     * is what makes the service reachable at all.
     */
    abstract public function operation(): Operation;

    final public function execute(Command $command, Actor $actor): Result
    {
        try {
            // BE-044 / SEC-053 ‡ — before the domain, every time, every caller.
            $this->authoriser->authorise($this->operation(), $actor, $this->target($command));

            return $this->handle($command, $actor);
        } catch (BusinessRefusal $refusal) {
            return Result::failed(BusinessRefused::from($refusal));
        }
    }

    /**
     * The record this operation acts on, as the platform holds it.
     *
     * `BE-181` ‡ / `SEC-056` ‡: ownership and relationship are evaluated against
     * platform state, never against an inbound claim — so a service that needs a
     * relationship checked loads the record here and returns it, rather than
     * passing identifiers the caller sent.
     *
     * Null where the operation acts on no particular record. A rule requiring a
     * relationship is then refused, because an unverifiable requirement is not a
     * met one.
     */
    protected function target(Command $command): ?AuthorisationTarget
    {
        return null;
    }

    abstract protected function handle(Command $command, Actor $actor): Result;
}
