<?php

declare(strict_types=1);

namespace Cmp\Application\Shared;

use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Application\Shared\Idempotency\IdempotentOperation;
use Cmp\Application\Shared\Idempotency\RegisteredOutcome;
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
 *
 * ## `API-062` ‡ is applied here, and that is `CC-045`
 *
 * `BADR-08`: *"every state-changing application service accepts an idempotency
 * key; a repeat with the same key returns the recorded outcome without
 * re-executing."* It did not, for any REST operation. `RequireIdempotencyKey`
 * checks the key is **present** and stops there — correctly, since `API-061` ‡
 * puts the registry entry *"in the same transaction as the effect it guards"*,
 * which is this layer's — and {@see IdempotentOperation}, the mechanism, was
 * invoked by `PlatformJob` and by nothing else. Every state-changing request on
 * the platform carried a key that did nothing.
 *
 * It is applied **here** rather than in each service for `SADR-06`'s reason,
 * which is the same reason authorisation is here: a guarantee each service has
 * to remember is one a service will eventually be written without. A service
 * cannot opt out — `execute()` is `final`, and the wrapping turns on the
 * command's own type.
 *
 * A {@see Command} that is not a {@see StateChangingCommand} skips it, because
 * `API-065`/`API-007` make a safe method carry no key and there would be none to
 * scope by. That is also what keeps a job from being registered twice:
 * `PlatformJob` is itself the state-changing command and wraps its own run, so
 * the service it invokes is handed a plain command.
 *
 * A state-changing service therefore answers with an {@see OperationOutcome} or
 * with nothing, and receives back a {@see RegisteredOutcome} — which carries
 * `API-064`'s distinction between a fresh outcome and a replayed one.
 */
abstract class ApplicationService
{
    public function __construct(
        private readonly Authoriser $authoriser,
        private readonly IdempotentOperation $idempotency,
    ) {}

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

            if (! $command instanceof StateChangingCommand) {
                return $this->handle($command, $actor);
            }

            // API-062 ‡ / BADR-08 / AADR-04 — here, so that no service can be
            // written without it. See the class note.
            //
            // The refusal is converted **inside** the work, not outside it, and
            // that is `FRD-FR-248` ‡ rather than tidiness: a service records the
            // refusal evidentially and then raises, so an exception crossing this
            // boundary would roll the transaction back and take the record with
            // it. A refusal the platform decided but did not evidence is one
            // `SEC-057` ‡ says did not properly happen.
            //
            // `IdempotentOperation` already treats a refusal as an outcome and
            // records it, so a retry under the same key receives the same answer
            // rather than re-running the work.
            return $this->idempotency->execute(
                $command,
                $this->operation()->name(),
                $actor->reference(),
                function () use ($command, $actor): Result {
                    try {
                        return $this->handle($command, $actor);
                    } catch (BusinessRefusal $refusal) {
                        return Result::failed(BusinessRefused::from($refusal));
                    }
                },
            );
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
