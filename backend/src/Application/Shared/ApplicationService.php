<?php

declare(strict_types=1);

namespace Cmp\Application\Shared;

use Cmp\Application\Shared\Failure\BusinessRefused;
use Cmp\Domain\Shared\Refusal\BusinessRefusal;

/**
 * The base every application service extends.
 *
 * `BE-041`: each use case is realised by exactly one application service
 * operation — one class, one `handle`.
 *
 * `BE-042`: the operation accepts a {@see Command} in application terms, not a
 * transport representation. `BE-043`: it is invocable from any caller without
 * HTTP context — nothing here reads a request, a session or a route.
 * `BE-046`: it returns a {@see Result} distinguishing success from each failure
 * class.
 *
 * **Only `BusinessRefusal` is caught.** `BE-186` ‡ forbids an internal fault
 * being represented as a business refusal; a broad catch here would do exactly
 * that, turning a defect into a refusal the caller would be told is final.
 * Anything else propagates, is rolled back and is recorded with correlation
 * identity (`BE-190`), and reaches the caller as an internal fault carrying that
 * identity and nothing else (`API-092` ‡).
 *
 * Two obligations attach to this base and are not yet implemented:
 *  - `BE-044` authorisation evaluated **before** the domain is invoked — the
 *    single application-layer path of `BADR-14` / `SADR-06` (`CMP-IMP-033`).
 *  - `BE-047` ‡ transaction boundaries owned here and nowhere else
 *    (`CMP-IMP-024`), with the idempotency registry entry written in the same
 *    transaction as the effect it guards (`BE-051` ‡, `CMP-IMP-025`).
 */
abstract class ApplicationService
{
    /**
     * @return Result<mixed>
     */
    final public function execute(Command $command): Result
    {
        try {
            return $this->handle($command);
        } catch (BusinessRefusal $refusal) {
            return Result::failed(BusinessRefused::from($refusal));
        }
    }

    /**
     * @return Result<mixed>
     */
    abstract protected function handle(Command $command): Result;
}
