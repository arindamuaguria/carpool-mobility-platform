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
 * `CMP-IMP-057` — terminates the caller's session and prevents its reuse.
 *
 * `FRD-FR-019`: *"terminate a session on the user's request and prevent its
 * reuse."* The two halves are done by different things, and neither is this
 * service's alone: `SEC-040` ‡ records the termination, and `DB-044` ‡ keeps the
 * record so that a later attempt to use the token is **detected** rather than
 * merely failing. {@see ResolveSession} is what detects it.
 *
 * `API-103` ‡ then makes the refusal identical to one for an unknown token
 * (`SEC-048` ‡), so a caller cannot learn from the refusal that the token was
 * once real.
 *
 * ## Idempotent, and it has to be
 *
 * `API-062` ‡: a repeated request with the same key returns the original outcome
 * and produces no second effect. Terminating an already-terminated session
 * succeeds and changes nothing — {@see Session::terminate()}
 * keeps the first instant, because `DB-044` ‡'s detection needs when the session
 * stopped being usable rather than when somebody asked again.
 *
 * ## `SEC-050`, in the same transaction
 *
 * *"Session establishment, refresh and termination shall each be evidenced."*
 * `BE-106` ‡ writes the record in the transaction that carries the effect, so a
 * termination that could not be evidenced does not stand — `FRD-FR-248` ‡.
 *
 * `BE-201` ‡: the record names the actor and the operation and carries no token,
 * no hash and no contact detail.
 */
final class TerminateCurrentSession extends ApplicationService
{
    public const ACTION = 'session.terminated';

    public function __construct(
        Authoriser $authoriser,
        private readonly TransactionBoundary $transaction,
        private readonly SessionRepository $sessions,
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
    ) {
        parent::__construct($authoriser);
    }

    public function operation(): Operation
    {
        return Operation::named('sessions.current.terminate');
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

        $session = $command->session();

        $this->transaction->transactional(function () use ($session, $actor): void {
            $session->terminate($this->clock->now());
            $this->sessions->save($session);

            // SEC-050, and BE-106 ‡ puts it here rather than after the commit.
            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor->reference()->toString()),
                self::ACTION,
                $session->user()->toString(),
                EvidentialOutcome::Succeeded,
                $this->clock->now(),
            ));
        });

        // FRD-FR-020 clears the device's cached business data when a session
        // ends. That is the client's, and MOB-065's outbox is where it happens;
        // nothing is returned here for it to act on beyond the success itself.
        return Result::succeeded();
    }
}
