<?php

declare(strict_types=1);

namespace Cmp\Application\Shared\StateMachine;

use Cmp\Application\Shared\Evidence\Evidence;
use Cmp\Application\Shared\Evidence\EvidentialOutcome;
use Cmp\Application\Shared\Evidence\RecordsEvidence;
use Cmp\Application\Shared\Idempotency\ActorReference;
use Cmp\Domain\Shared\StateMachine\StateMachine;
use Cmp\Domain\Shared\StateMachine\StateModel;
use Cmp\Domain\Shared\StateMachine\TransitionRefused;
use Cmp\Domain\Shared\Time\Clock;

/**
 * Applies a lifecycle transition and evidences it.
 *
 * `BE-178`: *"A transition shall be evidenced with its trigger and actor."*
 *
 * **The engine cannot do this itself, and that is the reason this class
 * exists.** {@see StateMachine} is Domain, and `BE-001`–`BE-003` allow the
 * Domain to depend on nothing — not on the evidential writer, not on a clock it
 * did not declare, and not on an actor, which is an application-layer notion
 * (`BE-044` evaluates authorisation before the domain is invoked, so the domain
 * never learns who asked). A transition and the evidence of it are therefore two
 * layers, joined here.
 *
 * ## What the record says
 *
 * - **action** — `<model>.<trigger>`. `BE-178` asks for the trigger; the model
 *   qualifies it, because `confirm` means different things to different
 *   aggregates and a log that could not tell them apart would be a poor one.
 * - **subject** — the aggregate's **external** identifier, supplied by the
 *   caller. `DB-022` ‡ and `DB-024` ‡ keep the internal key out of it.
 * - **actor** — `BE-178`, and `FRD-FR-246` ‡ where an operator acted.
 * - **reason** — absent on success (`DB-109` ‡ deliberately omits it from the
 *   `NOT NULL` list) and, on a refusal, the internal refusal case. `BE-114` ‡
 *   requires a reason there. It is the **case**, not
 *   {@see TransitionRefused::detail()}: the case is a fixed, bounded value, and
 *   the prose detail is written for a caller's error path, where `API-086` ‡ and
 *   `API-094` ‡ decide what may be said. Since the action already names the model
 *   and trigger and the subject names the aggregate, the case is what the record
 *   is missing and all it is missing.
 *
 * ## Refusals are evidenced too
 *
 * A refused transition is an outcome the platform decided, and `BE-114` ‡ says a
 * refused operation is evidenced with its reason. It is recorded and then
 * re-raised unchanged, so nothing downstream can tell the difference — except
 * that the refusal is now in the log.
 *
 * `BE-106` ‡ writes the record in the same transaction as the operation it
 * evidences, so both records here join whatever transaction the calling
 * application service opened. A caller that rolls that transaction back rolls
 * the record back with it, which is what `BE-106` ‡ specifies: the record and
 * the operation stand or fall together.
 *
 * **No aggregate uses this yet.** `BE-017` fixes nine aggregates and none is
 * built, and `PolicyServiceProvider` ships the engine with an empty invariant
 * list for the same reason. What exists here is the joining of the transition to
 * its evidence, which is `BE-178` and is not aggregate-specific.
 */
final class ApplyTransition
{
    public function __construct(
        private readonly StateMachine $machine,
        private readonly RecordsEvidence $evidence,
        private readonly Clock $clock,
    ) {}

    /**
     * The state reached, having recorded that it was reached.
     *
     * @param  string  $subject  the aggregate's external identifier (`DB-024` ‡)
     * @param  object|null  $aggregate  passed to the invariants that need it
     *
     * @throws TransitionRefused where the model does not declare the transition
     *                           (`BE-176` ‡) or an invariant refuses it
     *                           (`BE-177` ‡) — recorded before it is raised
     */
    public function apply(
        StateModel $model,
        string $currentState,
        string $trigger,
        ActorReference $actor,
        string $subject,
        ?object $aggregate = null,
    ): string {
        $action = self::actionFor($model, $trigger);

        try {
            $destination = $this->machine->apply($model, $currentState, $trigger, $aggregate);
        } catch (TransitionRefused $refused) {
            // BE-114 ‡: recorded with its refusal reason, then raised unchanged.
            $this->evidence->record(Evidence::of(
                $actor,
                $action,
                $subject,
                EvidentialOutcome::Refused,
                $this->clock->now(),
                $refused->refusal()->name,
            ));

            throw $refused;
        }

        // BE-178 / SRS-REQ-153: the time and cause of a transition. FRD-FR-248 ‡
        // — if this raises, the transition is not reported as having happened.
        $this->evidence->record(Evidence::of(
            $actor,
            $action,
            $subject,
            EvidentialOutcome::Succeeded,
            $this->clock->now(),
        ));

        return $destination;
    }

    /**
     * How a transition appears in the log.
     *
     * Public so that a reader of the log — or a test — derives the action the
     * same way this class writes it, rather than restating the convention.
     */
    public static function actionFor(StateModel $model, string $trigger): string
    {
        return $model->name().'.'.$trigger;
    }
}
