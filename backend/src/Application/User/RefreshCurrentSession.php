<?php

declare(strict_types=1);

namespace Cmp\Application\User;

use Cmp\Application\Shared\ApplicationService;
use Cmp\Application\Shared\Authorisation\Actor;
use Cmp\Application\Shared\Authorisation\AuthorisationTarget;
use Cmp\Application\Shared\Authorisation\Authoriser;
use Cmp\Application\Shared\Authorisation\Operation;
use Cmp\Application\Shared\Command;
use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Application\Shared\Result;
use Cmp\Application\Shared\Transaction\TransactionBoundary;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use LogicException;

/**
 * `CMP-IMP-056` — refreshes the caller's session within the bound.
 *
 * `SEC-043`: *"Refresh shall issue a new token and invalidate the previous
 * one."* Both halves, in one transaction: the old session is terminated
 * (`SEC-040` ‡) and a new one established with a new token (`SEC-035` ‡).
 *
 * ## Why the old row is terminated rather than updated
 *
 * `DB-044` ‡ records a terminated session and does not remove it, *"so that reuse
 * is detectable rather than merely impossible"*. A refresh that rewrote the token
 * on the existing row would leave nothing to detect: the old token would become
 * an **unknown** token rather than a terminated one, and an attacker replaying it
 * would look like a client with a typo.
 *
 * ## Why the count does not grow
 *
 * `SEC-049`'s limit of three is not consulted here and does not need to be: one
 * session ends as one begins, so refreshing cannot take a user past a limit they
 * were within. That matters, because **what the platform does when the limit is
 * reached is not stated by any requirement** and nothing here invents it —
 * establishment is where the limit bites, and establishment is blocked.
 *
 * ## Within the bound
 *
 * `NFR-055` extends a session *"within the bound"*, and this service never checks
 * it: {@see ResolveSession} already refused an expired session before the command
 * was built, so a refresh that reaches here is by construction inside the window.
 * Checking again would be a second place for `SEC-039` ‡ to be interpreted.
 *
 * ## What is returned, and what must happen to it
 *
 * The new token, once. `SEC-038` ‡ keeps it out of every log, diagnostic record
 * and error message, and the only place it may appear is the response that issues
 * it — `API-101` ‡ forbids a **credential** in a response, and this is the one
 * response whose purpose is to carry the new one, exactly as establishment's is.
 */
final class RefreshCurrentSession extends ApplicationService
{
    public const ACTION = 'session.refreshed';

    public function __construct(
        Authoriser $authoriser,
        private readonly TransactionBoundary $transaction,
        private readonly SessionRepository $sessions,
        private readonly HashesSessionTokens $tokens,
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
    ) {
        parent::__construct($authoriser);
    }

    public function operation(): Operation
    {
        return Operation::named('sessions.current.refresh');
    }

    protected function target(Command $command): ?AuthorisationTarget
    {
        return $command instanceof CurrentSessionCommand ? $command : null;
    }

    protected function handle(Command $command, Actor $actor): Result
    {
        if (! $command instanceof CurrentSessionCommand) {
            throw new LogicException(self::class.' acts on the session the caller holds.');
        }

        $previous = $command->session();
        $token = $this->tokens->generate();

        $this->transaction->transactional(function () use ($previous, $token, $actor): void {
            $now = $this->clock->now();

            // SEC-043, first half: the previous is invalidated. Terminated
            // rather than removed, so DB-044 ‡ keeps it detectable.
            $previous->terminate($now);
            $this->sessions->save($previous);

            // SEC-043, second half. A fresh establishment instant is what gives
            // the new session the whole of SEC-039 ‡'s bound.
            $this->sessions->save(Session::establish($previous->user(), $this->tokens->hash($token), $now));

            // SEC-050 / BE-106 ‡.
            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor->reference()->toString()),
                self::ACTION,
                $previous->user()->toString(),
                EvidentialOutcome::Succeeded,
                $now,
            ));
        });

        return Result::success($token);
    }
}
