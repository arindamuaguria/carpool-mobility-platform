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
use Cmp\Domain\Shared\Policy\PolicyKey;
use Cmp\Domain\Shared\Policy\PolicyStore;
use Cmp\Domain\Shared\Refusal\BusinessRefusal;
use Cmp\Domain\Shared\Time\Clock;
use Cmp\Domain\User\ConcurrentSessionLimit;
use Cmp\Domain\User\Session;
use Cmp\Domain\User\SessionRepository;
use Cmp\Domain\User\User;
use Cmp\Domain\User\UserRepository;
use LogicException;

/**
 * `CMP-IMP-053` — establishes a session for an authenticated identity.
 *
 * `FRD-FR-016`: *"establish an authenticated session on successful
 * authentication."* The authentication is upstream ({@see EstablishSessionCommand}
 * says why); what happens here is the three things the platform decides **after**
 * a caller has proved who they are, in the order the requirements put them.
 *
 * | Check | Statement | Refusal |
 * |---|---|---|
 * | The account state permits use | `SEC-051` ‡, `BAD-RULE-010`, `UC-003` E2 | `session.account_restricted` |
 * | The number has been demonstrated | `FRD-FR-018`, `UC-003` E3 | `session.verification_required` |
 * | The user is within `SEC-049`'s limit | `SEC-243` ‡ | `session.concurrent_limit_reached` |
 *
 * ## The order is deliberate
 *
 * State before standing, because `BAD-RULE-010` is absolute and `FRD-FR-018`'s
 * routing is an instruction to go and do something — sending a suspended user to
 * verify their number would have them complete a flow that still ends in refusal.
 *
 * Standing before the limit, because a user who cannot hold a session at all
 * should not be told about a limit on how many.
 *
 * ## `SEC-243` ‡ — refused, and nothing evicted
 *
 * *"Where a user already holds the number of concurrent sessions `SEC-049`
 * permits, establishment shall be **refused** ... **No existing session shall be
 * terminated to make room.**"*
 *
 * The count is of **usable** sessions ({@see SessionRepository::usableCountFor()}),
 * because `DB-044` ‡ keeps terminated rows forever and `SEC-039` ‡ expires live
 * ones — a count of rows would refuse a user holding nothing.
 *
 * There is no eviction anywhere in this class and no repository method that could
 * perform one. `SEC-046`'s terminate-all exists for suspected compromise and is a
 * deliberate operator act, not an answer to this.
 *
 * ## `SEC-050` and `BE-106` ‡
 *
 * *"Session establishment, refresh and termination shall each be evidenced."* The
 * record is written in the transaction that carries the effect, so an
 * establishment that could not be evidenced does not stand — `FRD-FR-248` ‡.
 *
 * A **refusal** is evidenced too, and outside a transaction because nothing
 * changed: `SEC-243` ‡ requires its refusal to be recorded in terms, and
 * `FRD-FR-011`'s treatment of an exhausted limit is the same shape. `BE-201` ‡
 * keeps the token, the hash and the phone number out of every one of these
 * records.
 *
 * ## What is not here
 *
 * No attempt bounding. `SEC-022` ‡ and `SEC-023` ‡ bound **attempts**, and an
 * attempt is a demonstration submitted — which happens upstream, against
 * `op_user_verifications` (`DB-043`). Counting establishments here would bound
 * the wrong thing and would leave the real bound unbuilt while looking built.
 */
final class EstablishSession extends ApplicationService
{
    public const ACTION = 'session.established';

    public const REFUSED_ACTION = 'session.establishment_refused';

    public function __construct(
        Authoriser $authoriser,
        private readonly TransactionBoundary $transaction,
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly HashesSessionTokens $tokens,
        private readonly PolicyStore $policy,
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
        private readonly PolicyKey $lifetime,
        private readonly PolicyKey $concurrentLimit,
    ) {
        parent::__construct($authoriser);
    }

    public function operation(): Operation
    {
        return Operation::named('sessions.establish');
    }

    protected function target(Command $command): ?AuthorisationTarget
    {
        return $command instanceof EstablishSessionCommand ? $command : null;
    }

    protected function handle(Command $command, Actor $actor): Result
    {
        if (! $command instanceof EstablishSessionCommand) {
            throw new LogicException(self::class.' establishes a session for an authenticated identity.');
        }

        $user = $this->users->forReference($command->user());

        if (! $user instanceof User) {
            // The identity was authenticated a moment ago, so the account was
            // there. Reaching here means it left between the two, which is a
            // fault rather than a decision — BE-186 ‡ keeps the two apart, and a
            // refusal would tell the caller the platform decided something.
            throw new LogicException(
                'SEC-044 ‡: a session is bound to the actor it authenticated, and this one names an account '
                .'the store no longer holds.'
            );
        }

        // SEC-051 ‡ / BAD-RULE-010 / UC-003 E2. Asked of the aggregate, which is
        // where BE-012 puts an absolute rule — this service does not compare
        // states, it asks whether use is permitted.
        if (! $user->permitsAuthenticatedUse()) {
            return $this->refuse($command, $actor, EstablishmentRefusal::AccountRestricted);
        }

        // FRD-FR-018 / UC-003 E3: routed to verification rather than into the
        // application. The caller has already demonstrated possession of the
        // number, so SEC-021's non-disclosure is not weakened by saying so.
        if (! $user->verificationStanding()->permitsParticipation()) {
            return $this->refuse($command, $actor, EstablishmentRefusal::VerificationRequired);
        }

        $now = $this->clock->now();
        $lifetime = $this->policy->read($this->lifetime)->asDurationInSeconds();

        // SEC-243 ‡ / SEC-049. The limit is policy configuration; that a fourth
        // is refused rather than making room is not.
        $limit = ConcurrentSessionLimit::of($this->policy->read($this->concurrentLimit)->asInteger());

        if ($limit->reachedBy($this->sessions->usableCountFor($user->reference(), $now, $lifetime))) {
            return $this->refuse($command, $actor, EstablishmentRefusal::ConcurrentLimitReached);
        }

        $token = $this->tokens->generate();

        $this->transaction->transactional(function () use ($user, $token, $actor, $now): void {
            // SEC-035 ‡ / SEC-036 ‡: a high-entropy token, and the store holds
            // only its hash.
            $this->sessions->save(Session::establish($user->reference(), $this->tokens->hash($token), $now));

            // SEC-050, and BE-106 ‡ puts it here rather than after the commit.
            $this->evidence->record(Evidence::of(
                ActorReference::fromString($actor->reference()->toString()),
                self::ACTION,
                $user->reference()->toString(),
                EvidentialOutcome::Succeeded,
                $now,
            ));
        });

        // SEC-038 ‡: the token reaches the response that issues it and nothing
        // else. It is returned rather than stored, and nothing above logs it.
        return Result::success($token);
    }

    /**
     * A refusal, recorded before it is returned.
     *
     * `SEC-243` ‡ requires the concurrent-limit refusal to be recorded, and the
     * other two are recorded on the same path rather than on a lesser one — an
     * operator investigating why somebody cannot sign in needs all three, and
     * `BE-202` forbids an operational log standing in for the evidential record.
     *
     * No transaction: nothing changed, so there is nothing for the record to
     * commit with. `BE-106` ‡ binds a record to the effect it evidences, and a
     * refusal has none.
     */
    private function refuse(
        EstablishSessionCommand $command,
        Actor $actor,
        EstablishmentRefusal $reason,
    ): Result {
        $this->evidence->record(Evidence::of(
            ActorReference::fromString($actor->reference()->toString()),
            self::REFUSED_ACTION,
            // BE-201 ‡: the reference and nothing else. No token, no hash, no
            // phone number.
            $command->user()->toString(),
            EvidentialOutcome::Refused,
            $this->clock->now(),
            // The record's own reason field, rather than the subject with the
            // identifier appended: an operator filtering by subject is asking
            // "what happened to this account", and a subject that varied by
            // outcome would answer a different question each time.
            $reason->identifier(),
        ));

        // Through BusinessRefusal so that ApplicationService::execute() converts
        // it on the one path every refusal takes — BE-186 ‡ keeps a decision
        // distinct from a fault, and this is a decision.
        throw new BusinessRefusal($reason);
    }
}
